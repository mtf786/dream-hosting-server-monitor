<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['server_id', 'metric_batch_id', 'sample_index', 'collected_at', 'cpu_percent', 'memory_total_bytes', 'memory_used_bytes'])]
class MetricSample extends Model
{
    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'cpu_percent' => 'decimal:2',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MetricBatch::class, 'metric_batch_id');
    }

    public function diskSamples(): HasMany
    {
        return $this->hasMany(DiskSample::class);
    }
}
