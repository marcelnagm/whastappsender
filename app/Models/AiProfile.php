<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProfile extends Model
{
    protected $fillable = [
        'user_id', 'instance_id', 'name', 'enabled', 'provider', 'model', 'api_key', 'base_url',
        'temperature', 'max_tokens', 'system_prompt', 'business_hours_only',
        'business_days', 'business_start', 'business_end', 'timezone',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'enabled' => 'boolean',
        'business_hours_only' => 'boolean',
        'temperature' => 'float',
        'max_tokens' => 'integer',
        'api_key' => 'encrypted',
    ];

    /**
     * Legacy API (users.ai_* fields) keeps working: push the provider-neutral values into the user's
     * "any instance" agent. The model is copied only when that agent uses Groq (the legacy provider).
     */
    public static function syncFromUser(User $user): void
    {
        $profile = static::where('user_id', $user->id)->whereNull('instance_id')->first();
        if (!$profile) {
            return;
        }
        $profile->fill([
            'temperature' => $user->ai_temperature ?? $profile->temperature,
            'max_tokens' => $user->ai_max_tokens ?? $profile->max_tokens,
            'system_prompt' => $user->ai_system_prompt,
            'business_hours_only' => (bool) $user->ai_business_hours_only,
        ]);
        if ($profile->provider === 'groq' && $user->ai_model) {
            $profile->model = $user->ai_model;
        }
        $profile->save();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function providerConfig(): array
    {
        return (array) config("ai.providers.{$this->provider}", []);
    }

    public function providerLabel(): string
    {
        return $this->providerConfig()['label'] ?? $this->provider;
    }

    /** Last 4 characters of the saved key, for the form ("key saved: ••••1234"). The key itself is never shown. */
    public function keyHint(): ?string
    {
        $key = (string) $this->api_key;

        return $key === '' ? null : '••••' . substr($key, -4);
    }
}
