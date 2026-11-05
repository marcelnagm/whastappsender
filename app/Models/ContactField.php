<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactField extends Model
{
    public const TYPES = ['text', 'number', 'date', 'select', 'boolean'];

    protected $fillable = ['user_id', 'key', 'label', 'type', 'options', 'position'];

    protected $casts = ['options' => 'array'];
}
