<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['metric_sample_id', 'filesystem', 'total_bytes', 'used_bytes'])]
class DiskSample extends Model
{
    public function metricSample(): BelongsTo
    {
        return $this->belongsTo(MetricSample::class);
    }
}
