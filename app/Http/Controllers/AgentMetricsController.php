<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgentMetricsRequest;
use App\Models\MetricBatch;
use App\Models\Server;
use App\Models\ServerStatusEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AgentMetricsController extends Controller
{
    public function store(StoreAgentMetricsRequest $request): JsonResponse
    {
        /** @var Server $server */
        $server = $request->attributes->get('agent_server');
        abort_if($server->status === 'disabled', 403, 'This server has been disabled.');

        $validated = $request->validated();
        $now = now('UTC');

        foreach ($validated['samples'] as $sample) {
            if ($sample['memory_used_bytes'] > $sample['memory_total_bytes']) {
                return response()->json(['message' => 'Used memory cannot exceed total memory.'], 422);
            }

            $collectedAt = Carbon::parse($sample['collected_at'])->utc();

            if ($collectedAt->gt($now->copy()->addMinutes(5)) || $collectedAt->lt($now->copy()->subDays(7))) {
                return response()->json(['message' => 'Sample timestamps must be within the past 7 days and no more than 5 minutes in the future.'], 422);
            }

            foreach ($sample['disks'] ?? [] as $disk) {
                if ($disk['used_bytes'] > $disk['total_bytes']) {
                    return response()->json(['message' => 'Used disk space cannot exceed total disk space.'], 422);
                }
            }
        }

        try {
            $stored = DB::transaction(function () use ($server, $validated): bool {
                $lockedServer = Server::query()->lockForUpdate()->findOrFail($server->id);
                abort_if($lockedServer->status === 'disabled', 403, 'This server has been disabled.');

                if (MetricBatch::where('server_id', $server->id)->where('batch_uuid', $validated['batch_uuid'])->exists()) {
                    return false;
                }

                $batch = MetricBatch::create([
                    'server_id' => $server->id,
                    'batch_uuid' => $validated['batch_uuid'],
                    'agent_version' => $validated['agent_version'] ?? $lockedServer->agent_version,
                    'sample_count' => count($validated['samples']),
                    'received_at' => now(),
                ]);

                foreach ($validated['samples'] as $index => $sample) {
                    $metricSample = $batch->samples()->create([
                        'server_id' => $server->id,
                        'sample_index' => $index,
                        'collected_at' => Carbon::parse($sample['collected_at'])->utc(),
                        'cpu_percent' => $sample['cpu_percent'],
                        'memory_total_bytes' => $sample['memory_total_bytes'],
                        'memory_used_bytes' => $sample['memory_used_bytes'],
                    ]);

                    if (! empty($sample['disks'])) {
                        $metricSample->diskSamples()->createMany($sample['disks']);
                    }
                }

                $previousStatus = $lockedServer->status;
                $lastSampleAt = collect($validated['samples'])
                    ->map(fn (array $sample): Carbon => Carbon::parse($sample['collected_at'])->utc())
                    ->max();

                $lockedServer->forceFill([
                    'status' => 'online',
                    'last_seen_at' => now(),
                    'last_sample_at' => $lastSampleAt,
                    'agent_version' => $validated['agent_version'] ?? $lockedServer->agent_version,
                ])->save();

                if ($previousStatus !== 'online') {
                    ServerStatusEvent::create([
                        'server_id' => $server->id,
                        'from_status' => $previousStatus,
                        'to_status' => 'online',
                        'reason' => 'metrics_received',
                    ]);
                }

                return true;
            });
        } catch (QueryException $exception) {
            $duplicate = MetricBatch::where('server_id', $server->id)
                ->where('batch_uuid', $validated['batch_uuid'])
                ->exists();

            if (! $duplicate) {
                throw $exception;
            }

            $stored = false;
        }

        return response()->json(['status' => $stored ? 'stored' : 'duplicate'], $stored ? 201 : 200);
    }
}
