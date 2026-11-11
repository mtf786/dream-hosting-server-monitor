<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnrollAgentRequest;
use App\Models\ServerCredential;
use App\Models\ServerEnrollmentToken;
use App\Models\ServerStatusEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentEnrollmentController extends Controller
{
    public function store(EnrollAgentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $plainCredential = bin2hex(random_bytes(32));

        $result = DB::transaction(function () use ($validated, $plainCredential): array {
            $token = ServerEnrollmentToken::query()
                ->where('token_hash', hash('sha256', $validated['enrollment_token']))
                ->lockForUpdate()
                ->first();

            abort_if(! $token || $token->consumed_at || $token->revoked_at || $token->expires_at->isPast(), 401, 'Enrollment token is invalid, expired, or already used.');

            $server = $token->server()->lockForUpdate()->firstOrFail();
            abort_unless($server->status === 'pending', 409, 'This server is not awaiting enrollment.');
            abort_unless($server->platform === $validated['platform'], 422, 'The reported platform does not match this server.');

            $token->forceFill(['consumed_at' => now()])->save();

            $credential = ServerCredential::create([
                'server_id' => $server->id,
                'key_id' => (string) Str::uuid(),
                'token_hash' => hash('sha256', $plainCredential),
            ]);

            $server->forceFill([
                'hostname' => $validated['hostname'],
                'os_version' => $validated['os_version'] ?? null,
                'agent_version' => $validated['agent_version'],
                'status' => 'online',
                'last_seen_at' => now(),
            ])->save();

            ServerStatusEvent::create([
                'server_id' => $server->id,
                'from_status' => 'pending',
                'to_status' => 'online',
                'reason' => 'agent_enrolled',
            ]);

            return [$server->uuid, $credential->key_id];
        });

        return response()->json([
            'server_id' => $result[0],
            'credential_id' => $result[1],
            'credential' => $plainCredential,
            'sample_interval_seconds' => 60,
        ], 201)->header('Cache-Control', 'no-store');
    }
}
