<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['alert_rule_id', 'server_id', 'status', 'observed_value', 'triggered_at', 'resolved_at', 'last_notified_at'])]
class AlertIncident extends Model
{
    protected function casts(): array
    {
        return [
            'observed_value' => 'decimal:2',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }
}
