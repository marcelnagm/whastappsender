<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiRule extends Model
{
    use HasFactory;

    protected $table = 'ai_rules';

    protected $fillable = [
        'user_id',
        'name',
        'priority',
        'is_active',
        'trigger_type',
        'trigger_value',
        'action',
        'action_payload',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'action_payload' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
