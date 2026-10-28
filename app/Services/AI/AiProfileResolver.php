<?php

namespace App\Services\AI;

use App\Models\AiProfile;
use App\Models\Instance;
use App\Models\User;

/**
 * Which agent answers a message that arrived on a given WhatsApp number:
 *  1. the agent assigned to that instance (if it is switched off, the AI stays silent on that number);
 *  2. otherwise the "any instance" agent;
 *  3. otherwise the settings the user had before agents existed (Groq), so nothing breaks.
 */
class AiProfileResolver
{
    public function forInstance(User $user, Instance $instance): ?AiProfile
    {
        $specific = AiProfile::where('user_id', $user->id)->where('instance_id', $instance->id)->first();
        if ($specific) {
            return $specific->enabled ? $specific : null;
        }

        $default = AiProfile::where('user_id', $user->id)->whereNull('instance_id')->first();
        if ($default) {
            return $default->enabled ? $default : null;
        }

        return $this->legacy($user);
    }

    /** Unsaved profile built from the old users.ai_* columns. */
    private function legacy(User $user): ?AiProfile
    {
        if (!$user->ai_enabled) {
            return null;
        }

        return new AiProfile([
            'user_id' => $user->id,
            'name' => 'Default agent',
            'enabled' => true,
            'provider' => 'groq',
            'model' => $user->ai_model ?: 'llama-3.3-70b-versatile',
            'temperature' => $user->ai_temperature ?? 0.7,
            'max_tokens' => $user->ai_max_tokens ?? 1024,
            'system_prompt' => $user->ai_system_prompt,
            'business_hours_only' => (bool) $user->ai_business_hours_only,
        ]);
    }
}
