<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsightReport extends Model
{
    protected $fillable = ['user_id', 'business_type', 'period_days', 'model', 'summary', 'blocks', 'data', 'status', 'created_by'];

    protected $casts = ['blocks' => 'array', 'data' => 'array'];
}
