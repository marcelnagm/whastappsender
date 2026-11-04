<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A label an account puts on conversations (e.g. "Budget", "Support", "VIP"). */
class ChatTag extends Model
{
    protected $fillable = ['user_id', 'name', 'color'];

    public function chats()
    {
        return $this->belongsToMany(Chat::class, 'chat_tag_links', 'tag_id', 'chat_id');
    }
}
