<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboundWebhook extends Model
{
    protected $fillable = ['user_id', 'name', 'url', 'secret', 'events', 'active', 'failures', 'last_delivery_at'];

    protected $casts = ['events' => 'array', 'active' => 'boolean', 'last_delivery_at' => 'datetime'];

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_id');
    }
}
