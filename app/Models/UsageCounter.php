<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageCounter extends Model
{
    protected $fillable = ['user_id', 'metric', 'period', 'used'];
}
