<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamGoal extends Model
{
    public const METRICS = ['deals_won', 'won_value', 'answered', 'first_response_minutes'];

    protected $fillable = ['user_id', 'member_id', 'metric', 'target'];

    protected $casts = ['target' => 'float'];
}
