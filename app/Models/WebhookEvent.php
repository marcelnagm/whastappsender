<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = ['provider', 'type', 'resource_id', 'status', 'note', 'payload'];

    protected $casts = ['payload' => 'array'];
}
