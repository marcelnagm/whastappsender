<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = ['user_id', 'contact_id', 'task_id', 'name', 'phone', 'email', 'starts_at', 'ends_at', 'status', 'token'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
}
