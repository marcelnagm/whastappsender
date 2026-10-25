<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['subscription_id', 'user_id', 'type', 'data'];

    protected $casts = ['data' => 'array'];
}
