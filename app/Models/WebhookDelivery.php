<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_id', 'event', 'uuid', 'payload', 'attempts', 'status_code', 'ok', 'error'];

    protected $casts = ['ok' => 'boolean'];
}
