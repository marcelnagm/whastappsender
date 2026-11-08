<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A deal's stay in a stage: when it entered and when it left (null = still there). */
class DealStageEntry extends Model
{
    public $timestamps = false;

    protected $fillable = ['deal_id', 'user_id', 'pipeline_id', 'stage_id', 'entered_at', 'left_at'];

    protected $casts = ['entered_at' => 'datetime', 'left_at' => 'datetime'];
}
