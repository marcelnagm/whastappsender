<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SequenceStep extends Model
{
    protected $fillable = ['sequence_id', 'position', 'delay_minutes', 'body'];
}
