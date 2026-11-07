<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An answer the team reuses: longer than a quick reply, found by words in the title, text or keywords. */
class KbArticle extends Model
{
    protected $fillable = ['user_id', 'title', 'body', 'keywords', 'active', 'uses', 'created_by'];

    protected $casts = ['active' => 'boolean'];
}
