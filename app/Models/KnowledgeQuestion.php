<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A customer question the knowledge could not answer: the owner turns it into a FAQ. */
class KnowledgeQuestion extends Model
{
    protected $fillable = ['user_id', 'question', 'best_score', 'hits'];
}
