<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypebotSession extends Model
{
    protected $fillable = ['user_id', 'chat_id', 'session_id', 'status', 'choices', 'last_activity_at'];

    protected $casts = ['choices' => 'array', 'last_activity_at' => 'datetime'];
}
