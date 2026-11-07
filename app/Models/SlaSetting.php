<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlaSetting extends Model
{
    protected $fillable = ['user_id', 'enabled', 'first_response_minutes', 'escalate_after_minutes', 'escalate_to', 'reassign', 'business_only'];

    protected $casts = ['enabled' => 'boolean', 'reassign' => 'boolean', 'business_only' => 'boolean', 'first_response_minutes' => 'integer', 'escalate_after_minutes' => 'integer'];

    public static function for(int $userId): self
    {
        return static::firstOrNew(['user_id' => $userId], ['enabled' => false, 'first_response_minutes' => 15, 'escalate_after_minutes' => 30, 'reassign' => false, 'business_only' => true]);
    }
}
