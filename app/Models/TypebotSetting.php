<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypebotSetting extends Model
{
    protected $fillable = ['user_id', 'enabled', 'base_url', 'public_id', 'instance_id', 'handoff_keywords', 'on_finish', 'handoff_message', 'handoff_to', 'expire_minutes', 'only_outside_hours'];

    protected $casts = ['enabled' => 'boolean', 'only_outside_hours' => 'boolean', 'expire_minutes' => 'integer'];

    public static function for(int $userId): self
    {
        return static::firstOrNew(['user_id' => $userId], ['enabled' => false, 'base_url' => 'https://typebot.io', 'on_finish' => 'none', 'expire_minutes' => 720]);
    }

    /** @return array<int,string> lower case words/phrases */
    public function keywords(): array
    {
        return array_values(array_filter(array_map(fn ($k) => mb_strtolower(trim($k)), explode(',', (string) $this->handoff_keywords))));
    }
}
