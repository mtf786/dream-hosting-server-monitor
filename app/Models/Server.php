<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'uuid', 'name', 'platform', 'os_version', 'hostname', 'agent_version', 'status', 'last_seen_at', 'last_sample_at'])]
class Server extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_sample_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function enrollmentTokens(): HasMany
    {
        return $this->hasMany(ServerEnrollmentToken::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ServerCredential::class);
    }

    public function metricSamples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(MetricSample::class)->latestOfMany('collected_at');
    }
}
