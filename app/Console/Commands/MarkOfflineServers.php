<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerStatusEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('monitor:mark-offline')]
#[Description('Mark servers offline after three missed one-minute heartbeats')]
class MarkOfflineServers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $markedOffline = 0;

        Server::query()
            ->where('status', 'online')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', now()->subMinutes(3))
            ->orderBy('id')
            ->chunkById(100, function ($servers) use (&$markedOffline): void {
                foreach ($servers as $candidate) {
                    DB::transaction(function () use ($candidate, &$markedOffline): void {
                        $server = Server::query()->lockForUpdate()->find($candidate->id);

                        if (! $server || $server->status !== 'online' || $server->last_seen_at?->gte(now()->subMinutes(3))) {
                            return;
                        }

                        $previousStatus = $server->status;
                        $server->forceFill(['status' => 'offline'])->save();

                        ServerStatusEvent::create([
                            'server_id' => $server->id,
                            'from_status' => $previousStatus,
                            'to_status' => 'offline',
                            'reason' => 'heartbeat_timeout',
                        ]);

                        $markedOffline++;
                    });
                }
            });

        $this->info("Marked {$markedOffline} server(s) offline.");

        return self::SUCCESS;
    }
}
