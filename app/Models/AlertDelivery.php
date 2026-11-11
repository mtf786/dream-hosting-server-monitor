<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['alert_incident_id', 'channel', 'status', 'attempts', 'last_error', 'sent_at'])]
class AlertDelivery extends Model
{
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(AlertIncident::class, 'alert_incident_id');
    }
}
