<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('monitor:prune-metrics')]
#[Description('Delete metric batches older than the configured retention period')]
class PruneMetrics extends Command
{
    public function handle(): int
    {
        $retentionDays = max(1, (int) config('monitor.metrics_retention_days', 30));
        $cutoff = now()->subDays($retentionDays);
        $deletedBatches = 0;

        DB::table('metric_batches')
            ->where('received_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(500, function ($batches) use (&$deletedBatches): void {
                $deletedBatches += DB::table('metric_batches')
                    ->whereIn('id', $batches->pluck('id'))
                    ->delete();
            });

        $this->info("Deleted {$deletedBatches} metric batch(es) beyond the {$retentionDays}-day retention window.");

        return self::SUCCESS;
    }
}
