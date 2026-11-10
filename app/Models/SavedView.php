<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedView extends Model
{
    protected $fillable = ['user_id', 'created_by', 'scope', 'name', 'query', 'shared'];
    protected $casts = ['shared' => 'boolean'];
}
