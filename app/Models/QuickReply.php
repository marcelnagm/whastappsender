<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickReply extends Model
{
    protected $fillable = ['user_id', 'shortcut', 'title', 'body', 'created_by', 'uses'];

    /** Lower case, no "/" and no spaces: what is typed after the slash. */
    public static function cleanShortcut(string $s): string
    {
        return mb_substr(preg_replace('/[^\p{L}\p{N}_-]+/u', '', mb_strtolower(ltrim(trim($s), '/'))), 0, 40);
    }
}
