<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A reason the account offers when a deal is marked as lost (the report groups by it). */
class LostReason extends Model
{
    protected $fillable = ['user_id', 'name'];
}
