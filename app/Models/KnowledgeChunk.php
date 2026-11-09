<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeChunk extends Model
{
    protected $fillable = ['source_id', 'user_id', 'position', 'content', 'embedding', 'embedder'];
}
