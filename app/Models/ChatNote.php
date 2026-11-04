<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Internal note on a conversation: visible to the team only, never sent to the customer. */
class ChatNote extends Model
{
    protected $fillable = ['user_id', 'chat_id', 'author_id', 'body'];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
