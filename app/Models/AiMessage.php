<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiMessage extends Model
{
    use HasFactory;

    protected $table = 'ai_messages';

    protected $fillable = [
        'ai_session_id',
        'direction',
        'role',
        'channel_message_id',
        'content',
        'tokens_in',
        'tokens_out',
        'provider',
        'model',
        'status',
        'error',
        'raw_payload',
    ];

    protected $casts = [
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'raw_payload' => 'array',
    ];

    public function session()
    {
        return $this->belongsTo(AiSession::class, 'ai_session_id');
    }
}
