<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['server_id', 'batch_uuid', 'agent_version', 'sample_count', 'received_at'])]
class MetricBatch extends Model
{
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }
}
