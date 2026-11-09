<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Something the account taught the AI: a FAQ, a text, a file or a knowledge-base article. */
class KnowledgeSource extends Model
{
    protected $fillable = ['user_id', 'type', 'title', 'uri', 'content', 'status', 'error', 'chunks_count', 'embedder', 'last_indexed_at'];

    protected $casts = ['last_indexed_at' => 'datetime'];

    public function chunks()
    {
        return $this->hasMany(KnowledgeChunk::class, 'source_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn (KnowledgeSource $s) => app(\App\Services\Rag\VectorStore::class)->forget($s->id));
    }
}
