<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Automation extends Model
{
    protected $fillable = ['user_id', 'name', 'active', 'trigger', 'conditions', 'actions', 'runs', 'last_run_at'];

    protected $casts = ['active' => 'boolean', 'conditions' => 'array', 'actions' => 'array', 'last_run_at' => 'datetime'];
}
