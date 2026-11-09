<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    protected $table = 'ai_usage';
    public $timestamps = false;
    protected $fillable = ['user_id', 'kind', 'ai_profile_id', 'provider', 'model', 'tokens_in', 'tokens_out', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
