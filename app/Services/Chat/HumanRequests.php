<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\User;
use App\Notifications\AutomationNotice;

/**
 * "A person is needed": a customer who asks for an attendant (or a bot that hands over) is flagged on the conversation, so
 * the team sees it on the dashboard and in the chat filter. It clears when a person answers or the conversation is closed.
 * "Pending" is the manual cousin: the team marks a conversation as owing the customer an answer.
 */
class HumanRequests
{
    /** Phrases (after lower-casing, accents and punctuation removed) that mean "I want to talk to a person". */
    public static function phrases(): array
    {
        $extra = array_filter(array_map('trim', explode(',', (string) config('chat.human_keywords', ''))));

        return array_merge([
            'atendente', 'atendimento humano', 'falar com humano', 'falar com um humano', 'falar com uma pessoa', 'falar com alguem', 'falar com um atendente', 'pessoa de verdade',
            'talk to a human', 'talk to a person', 'speak to a human', 'speak to a person', 'speak with a human', 'real person', 'human agent', 'customer service agent', 'talk to an agent', 'talk to someone',
            'hablar con una persona', 'hablar con un humano', 'hablar con alguien', 'hablar con un agente', 'persona real', 'agente humano',
        ], $extra);
    }

    public static function detect(?string $text): bool
    {
        $t = ' ' . trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(self::unaccent((string) $text)))) . ' ';
        if (trim($t) === '') {
            return false;
        }
        foreach (self::phrases() as $p) {
            $p = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(self::unaccent($p))));
            if ($p !== '' && str_contains($t, ' ' . $p . ' ')) {
                return true;
            }
        }

        return false;
    }

    /** Flags the conversation (once) and tells the person in charge, or the owner. */
    public function request(Chat $chat, string $reason, bool $notify = true): void
    {
        if ($chat->needs_human_at || $chat->closed_at) {
            return;
        }
        $chat->forceFill(['needs_human_at' => now(), 'needs_human_reason' => mb_substr($reason, 0, 160)])->save();

        if ($notify) {
            $to = ($chat->assigned_to ? User::find($chat->assigned_to) : null) ?: User::find($chat->user_id);
            $to?->notify(new AutomationNotice(__('A customer needs a person: :name', ['name' => $chat->displayName()]), route('chat.index', ['chat' => $chat->id])));
        }
    }

    public function clearHuman(Chat $chat): void
    {
        if ($chat->needs_human_at) {
            $chat->forceFill(['needs_human_at' => null, 'needs_human_reason' => null])->save();
        }
    }

    public function markPending(Chat $chat, ?User $by = null): void
    {
        $chat->forceFill(['pending_at' => now(), 'pending_by' => $by?->id])->save();
    }

    public function clearPending(Chat $chat): void
    {
        if ($chat->pending_at) {
            $chat->forceFill(['pending_at' => null, 'pending_by' => null])->save();
        }
    }

    /** A person answered (or the conversation was closed): nothing is waiting on us any more. */
    public function answered(Chat $chat): void
    {
        $this->clearHuman($chat);
        $this->clearPending($chat);
    }

    private static function unaccent(string $s): string
    {
        return \Illuminate\Support\Str::ascii($s);
    }
}
