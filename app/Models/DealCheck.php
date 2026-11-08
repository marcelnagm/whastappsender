<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One checklist item ticked on a deal while it sat in a stage. */
class DealCheck extends Model
{
    protected $fillable = ['deal_id', 'stage_id', 'item_hash', 'done_by'];
}
