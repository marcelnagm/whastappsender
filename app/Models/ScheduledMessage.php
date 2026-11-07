<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledMessage extends Model
{
    protected $fillable = ['user_id', 'chat_id', 'created_by', 'body', 'send_at', 'cancel_on_reply', 'status', 'note', 'message_id', 'sent_at'];

    protected $casts = ['send_at' => 'datetime', 'sent_at' => 'datetime', 'cancel_on_reply' => 'boolean'];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }
}
