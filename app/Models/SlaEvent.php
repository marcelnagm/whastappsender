<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlaEvent extends Model
{
    protected $fillable = ['user_id', 'chat_id', 'inbound_message_id', 'kind', 'assigned_to', 'waited_minutes', 'resolved_at'];

    protected $casts = ['resolved_at' => 'datetime'];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }
}
