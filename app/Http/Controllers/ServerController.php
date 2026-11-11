<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServerRequest;
use App\Models\Server;
use App\Models\ServerEnrollmentToken;
use App\Models\ServerStatusEvent;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(): View
    {
        $servers = Server::query()
            ->where('user_id', auth()->id())
            ->with('latestMetric.diskSamples')
            ->orderBy('name')
            ->paginate(20);

        return view('servers.index', compact('servers'));
    }

    public function create(): View
    {
        return view('servers.create');
    }

    public function store(StoreServerRequest $request): RedirectResponse
    {
        $plainToken = bin2hex(random_bytes(32));
        $validated = $request->validated();

        $server = DB::transaction(function () use ($request, $validated, $plainToken): Server {
            $server = Server::create([
                'user_id' => $request->user()->id,
                'uuid' => (string) Str::uuid(),
                'name' => $validated['name'],
                'platform' => $validated['platform'],
                'status' => 'pending',
            ]);

            ServerEnrollmentToken::create([
                'server_id' => $server->id,
                'created_by' => $request->user()->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addMinutes(30),
            ]);

            return $server;
        });

        session()->flash('server_enrollment_token_'.$server->uuid, Crypt::encryptString($plainToken));

        $redirect = redirect()
            ->route('servers.enrollment', $server)
            ->with('status', 'Server registered. Copy the one-time enrollment token from this page within 30 minutes.');

        $redirect->headers->set('Cache-Control', 'no-store, private');

        return $redirect;
    }

    public function enrollment(Server $server): Response|RedirectResponse
    {
        $this->authorizeServer($server);

        $encryptedToken = session()->pull('server_enrollment_token_'.$server->uuid);

        if (! is_string($encryptedToken)) {
            return redirect()
                ->route('servers.show', $server)
                ->with('status', 'The enrollment token is no longer available. Register a replacement server to generate a new token.');
        }

        try {
            $plainToken = Crypt::decryptString($encryptedToken);
        } catch (DecryptException) {
            return redirect()
                ->route('servers.show', $server)
                ->with('status', 'The enrollment token could not be recovered from this browser session. Register a replacement server to generate a new token.');
        }

        return response()
            ->view('servers.enrollment-created', compact('server', 'plainToken'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function reissueEnrollment(Server $server, Request $request): RedirectResponse
    {
        $this->authorizeServer($server);
        $plainToken = bin2hex(random_bytes(32));

        DB::transaction(function () use ($server, $request, $plainToken): void {
            $server->enrollmentTokens()
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lockedServer = Server::query()->lockForUpdate()->findOrFail($server->id);
            abort_unless($lockedServer->status === 'pending', 409, 'Only pending servers can receive a replacement enrollment token.');

            $lockedServer->enrollmentTokens()
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            $lockedServer->enrollmentTokens()->create([
                'created_by' => $request->user()->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addMinutes(30),
            ]);
        });

        session()->flash('server_enrollment_token_'.$server->uuid, Crypt::encryptString($plainToken));

        $redirect = redirect()
            ->route('servers.enrollment', $server)
            ->with('status', 'A new one-time enrollment token was created. Any previous unused token was revoked; copy the new token from this page within 30 minutes.');

        $redirect->headers->set('Cache-Control', 'no-store, private');

        return $redirect;
    }

    public function show(Server $server): View
    {
        $this->authorizeServer($server);
        $server->load('latestMetric.diskSamples');

        return view('servers.show', compact('server'));
    }

    public function metrics(Server $server, Request $request): JsonResponse
    {
        $this->authorizeServer($server);
        $validated = $request->validate(['hours' => ['sometimes', 'integer', 'min:1', 'max:168']]);
        $hours = (int) ($validated['hours'] ?? 24);

        $samples = $server->metricSamples()
            ->with('diskSamples')
            ->where('collected_at', '>=', now()->subHours($hours))
            ->orderBy('collected_at')
            ->limit(2000)
            ->get();

        return response()->json([
            'labels' => $samples->map(fn ($sample): string => $sample->collected_at->toIso8601String())->values(),
            'cpu' => $samples->map(fn ($sample): float => (float) $sample->cpu_percent)->values(),
            'memory' => $samples->map(fn ($sample): float => round($sample->memory_used_bytes / $sample->memory_total_bytes * 100, 2))->values(),
            'disk' => $samples->map(function ($sample): ?float {
                $total = $sample->diskSamples->sum('total_bytes');
                $used = $sample->diskSamples->sum('used_bytes');

                return $total > 0 ? round($used / $total * 100, 2) : null;
            })->values(),
        ]);
    }

    public function disable(Server $server): RedirectResponse
    {
        $this->authorizeServer($server);

        DB::transaction(function () use ($server): void {
            $lockedServer = Server::query()->lockForUpdate()->findOrFail($server->id);

            if ($lockedServer->status === 'disabled') {
                return;
            }

            ServerStatusEvent::create([
                'server_id' => $lockedServer->id,
                'from_status' => $lockedServer->status,
                'to_status' => 'disabled',
                'reason' => 'disabled_by_admin',
            ]);
            $lockedServer->forceFill(['status' => 'disabled'])->save();
            $lockedServer->credentials()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });

        return redirect()->route('servers.index')->with('status', 'Server disabled and agent credentials revoked.');
    }

    private function authorizeServer(Server $server): void
    {
        abort_unless($server->user_id === auth()->id(), 404);
    }
}
