<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    public const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    protected $fillable = [
        'chat_id',
        'instance_id',
        'user_id',
        'message_id',
        'client_id',
        'from_me',
        'type',
        'body',
        'mimetype',
        'file_name',
        'media_path',
        'quoted_message_id',
        'quoted_preview',
        'reactions',
        'status',
        'error',
        'deleted',
        'message_timestamp',
    ];

    protected $casts = [
        'from_me' => 'boolean',
        'deleted' => 'boolean',
        'reactions' => 'array',
        'message_timestamp' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function hasMedia(): bool
    {
        return in_array($this->type, self::MEDIA_TYPES, true) && !$this->deleted;
    }

    /**
     * Shape consumed by public/assets/js/chat.js.
     *
     * @param array<string,bool> $quotedFromMe map of message_id => from_me for quoted messages
     */
    public function toApi(array $quotedFromMe = []): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'message_id' => $this->message_id,
            'from_me' => $this->from_me,
            'type' => $this->type,
            'body' => $this->deleted ? null : $this->body,
            'mimetype' => $this->mimetype,
            'file_name' => $this->file_name,
            'media_url' => $this->hasMedia() ? route('chat.media', $this->id) : null,
            'quoted' => $this->quoted_message_id || $this->quoted_preview ? [
                'preview' => $this->quoted_preview,
                'from_me' => $quotedFromMe[$this->quoted_message_id] ?? null,
            ] : null,
            'reactions' => array_values(array_filter(array_values($this->reactions ?? []))),
            'status' => $this->status,
            'error' => $this->error,
            'deleted' => $this->deleted,
            'ts' => optional($this->message_timestamp ?? $this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
