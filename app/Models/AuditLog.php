<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'actor_id', 'actor_name', 'action', 'method', 'path', 'subject', 'ip', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
