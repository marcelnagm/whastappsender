<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CsatSurvey extends Model
{
    protected $fillable = ['user_id', 'chat_id', 'agent_id', 'scale', 'status', 'score', 'sent_at', 'answered_at'];

    protected $casts = ['sent_at' => 'datetime', 'answered_at' => 'datetime', 'score' => 'integer'];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }
}
