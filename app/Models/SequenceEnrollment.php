<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SequenceEnrollment extends Model
{
    protected $fillable = ['user_id', 'sequence_id', 'chat_id', 'enrolled_by', 'next_position', 'next_at', 'status', 'stop_reason'];

    protected $casts = ['next_at' => 'datetime'];

    public function sequence()
    {
        return $this->belongsTo(Sequence::class);
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }
}
