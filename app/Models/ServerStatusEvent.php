<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_id', 'from_status', 'to_status', 'reason'])]
class ServerStatusEvent extends Model
{
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
