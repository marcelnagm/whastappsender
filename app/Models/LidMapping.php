<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LidMapping extends Model
{
    protected $fillable = ['lid', 'phone', 'source'];
}
