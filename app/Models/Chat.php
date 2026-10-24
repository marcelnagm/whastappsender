<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    protected $fillable = [
        'user_id',
        'instance_id',
        'remote_jid',
        'phone',
        'contact_id',
        'name',
        'avatar_url',
        'avatar_checked_at',
        'unread_count',
        'archived',
        'last_message_id',
        'last_message_type',
        'last_message_preview',
        'last_message_from_me',
        'last_message_status',
        'last_message_at',
    ];

    protected $casts = [
        'archived' => 'boolean',
        'last_message_from_me' => 'boolean',
        'unread_count' => 'integer',
        'last_message_at' => 'datetime',
        'avatar_checked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * Value sent to Evolution in the "number" field (digits when we know them, otherwise the JID).
     */
    public function sendNumber(): string
    {
        return $this->phone ?: $this->remote_jid;
    }

    public function displayName(): string
    {
        if ($this->contact && $this->contact->name) {
            return $this->contact->name;
        }

        if ($this->name) {
            return $this->name;
        }

        return $this->phone ? '+' . $this->phone : $this->remote_jid;
    }

    public function avatar(): ?string
    {
        return $this->avatar_url ?: optional($this->contact)->profile_url;
    }
}
