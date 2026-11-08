<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BookingSetting extends Model
{
    protected $fillable = ['user_id', 'enabled', 'slug', 'title', 'description', 'duration_minutes', 'min_notice_hours', 'max_days'];

    protected $casts = ['enabled' => 'boolean', 'duration_minutes' => 'integer', 'min_notice_hours' => 'integer', 'max_days' => 'integer'];

    public static function for(int $userId): self
    {
        return static::firstOrNew(['user_id' => $userId], ['enabled' => false, 'slug' => Str::lower(Str::random(10)), 'title' => 'Meeting', 'duration_minutes' => 30, 'min_notice_hours' => 2, 'max_days' => 30]);
    }
}
