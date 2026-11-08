<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealFollower extends Model
{
    protected $fillable = ['deal_id', 'user_id'];
}
