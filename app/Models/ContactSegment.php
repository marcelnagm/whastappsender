<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactSegment extends Model
{
    protected $fillable = ['user_id', 'name', 'filters', 'created_by'];

    protected $casts = ['filters' => 'array'];
}
