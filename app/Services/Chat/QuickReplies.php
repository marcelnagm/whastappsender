<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\QuickReply;
use App\Models\User;

/** Fills the variables of a canned answer for one conversation. */
class QuickReplies
{
    public const VARIABLES = ['name', 'first_name', 'phone', 'agent', 'company', 'today'];

    /** Text ready to send: {{name}} {{first_name}} {{phone}} {{agent}} {{company}} {{today}}; an unknown {{word}} is left as typed. */
    public function render(QuickReply $reply, ?Chat $chat, User $agent, User $owner): string
    {
        $name = $chat ? trim((string) ($chat->contact?->name ?: $chat->name)) : '';
        if ($name !== '' && str_starts_with($name, '+')) {
            $name = ''; // a phone number is not a name to greet
        }
        $vars = [
            'name' => $name,
            'first_name' => $name !== '' ? (preg_split('/\s+/u', $name)[0] ?? '') : '',
            'phone' => $chat && $chat->phone ? '+' . $chat->phone : '',
            'agent' => $agent->chat_signature ?: $agent->name,
            'company' => $owner->name,
            'today' => now()->format('d/m/Y'),
        ];

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars) {
            $k = strtolower($m[1]);

            return array_key_exists($k, $vars) ? $vars[$k] : $m[0];
        }, $reply->body);
    }
}
