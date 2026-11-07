<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceHours extends Model
{
    protected $table = 'service_hours';

    protected $fillable = ['user_id', 'timezone', 'business_days', 'business_start', 'business_end', 'away_enabled', 'away_message', 'away_every_hours', 'greeting_enabled', 'greeting_message'];

    protected $casts = ['away_enabled' => 'boolean', 'greeting_enabled' => 'boolean', 'away_every_hours' => 'integer'];

    public static function for(int $userId): self
    {
        return static::firstOrNew(['user_id' => $userId], [
            'timezone' => config('ai.business_hours.timezone'), 'business_days' => '1,2,3,4,5', 'business_start' => '09:00', 'business_end' => '18:00', 'away_every_hours' => 8,
        ]);
    }
}
