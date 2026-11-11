<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'server_id', 'name', 'metric', 'operator', 'threshold', 'duration_seconds', 'cooldown_seconds', 'enabled'])]
class AlertRule extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'threshold' => 'decimal:2'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(AlertIncident::class);
    }
}
