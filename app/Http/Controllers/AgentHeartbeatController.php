<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerStatusEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentHeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Server $server */
        $server = $request->attributes->get('agent_server');
        $validated = $request->validate([
            'agent_version' => ['sometimes', 'string', 'max:64'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($server, $validated): JsonResponse {
            $lockedServer = Server::query()->lockForUpdate()->findOrFail($server->id);
            abort_if($lockedServer->status === 'disabled', 403, 'This server has been disabled.');
            $previousStatus = $lockedServer->status;

            $lockedServer->forceFill([
                'status' => 'online',
                'last_seen_at' => now(),
                'agent_version' => $validated['agent_version'] ?? $lockedServer->agent_version,
                'os_version' => $validated['os_version'] ?? $lockedServer->os_version,
            ])->save();

            if ($previousStatus !== 'online') {
                ServerStatusEvent::create([
                    'server_id' => $lockedServer->id,
                    'from_status' => $previousStatus,
                    'to_status' => 'online',
                    'reason' => 'heartbeat_received',
                ]);
            }

            return response()->json(['status' => 'online', 'server_id' => $lockedServer->uuid]);
        });
    }
}
