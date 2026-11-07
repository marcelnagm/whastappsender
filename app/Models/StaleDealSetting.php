<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaleDealSetting extends Model
{
    protected $fillable = ['user_id', 'enabled', 'days'];

    protected $casts = ['enabled' => 'boolean', 'days' => 'integer'];

    public static function for(int $userId): self
    {
        return static::firstOrNew(['user_id' => $userId], ['enabled' => false, 'days' => 7]);
    }
}
