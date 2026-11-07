<?php

namespace App\Services\Chat;

use App\Jobs\SendAiReplyJob;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ServiceHours;
use App\Models\User;
use App\Services\AI\AiBusinessHours;
use App\Services\AI\AiProfileResolver;

/**
 * Greeting (first message of a new conversation, during opening hours) and away message (a customer writes while closed).
 * Both leave through the channel and number the customer wrote to, are marked as automatic (they never count as a person's
 * answer in reports) and are skipped when they would only get in the way: AI agent answering, opted-out contact, groups.
 */
class AutoMessages
{
    public const GREETING = 'greeting';
    public const AWAY = 'away';

    /** @return ?string which message was queued, if any */
    public function onInbound(Chat $chat, User $owner, ChatMessage $m): ?string
    {
        if ($m->from_me || str_ends_with((string) $chat->remote_jid, '@g.us')) {
            return null;
        }
        $cfg = ServiceHours::where('user_id', $owner->id)->first();
        if (!$cfg || (!$cfg->away_enabled && !$cfg->greeting_enabled)) {
            return null;
        }
        $contact = $chat->contact;
        if (!$contact || (int) ($contact->ignore_me ?? 0) === 1 || $contact->consent_status === 'revoked' || $contact->status === 'no-whatsapp') {
            return null;
        }
        $instance = $chat->instance;
        if (!$instance) {
            return null;
        }

        $open = AiBusinessHours::isOpen($cfg);
        $profile = $this->answeringAgent($owner, $instance);
        $first = !ChatMessage::where('chat_id', $chat->id)->where('from_me', true)->exists()
            && ChatMessage::where('chat_id', $chat->id)->where('from_me', false)->where('id', '<', $m->id)->doesntExist();

        $kind = null;
        if (!$open && $cfg->away_enabled && trim((string) $cfg->away_message) !== '') {
            // an agent that stays silent outside its hours leaves the customer without any answer: the away message covers that
            $agentCovers = $profile && !$profile->business_hours_only;
            $recent = $chat->away_sent_at && $chat->away_sent_at->gt(now()->subHours(max(1, (int) $cfg->away_every_hours)));
            if (!$agentCovers && !$recent) {
                $kind = self::AWAY;
            }
        } elseif ($open && $cfg->greeting_enabled && trim((string) $cfg->greeting_message) !== '' && $first && !$chat->greeted_at && !$profile) {
            $kind = self::GREETING;
        }
        if (!$kind) {
            return null;
        }

        $text = $this->render($kind === self::AWAY ? $cfg->away_message : $cfg->greeting_message, $chat, $owner, $cfg);
        $session = AiSession::firstOrCreate(
            ['user_id' => $owner->id, 'contact_id' => $contact->id, 'instance_id' => $instance->id],
            ['status' => 'active', 'human_handoff' => false]
        );
        $out = AiMessage::create(['ai_session_id' => $session->id, 'direction' => 'outbound', 'role' => 'assistant', 'content' => $text, 'provider' => 'auto', 'model' => $kind, 'status' => 'queued']);
        $chat->forceFill($kind === self::AWAY ? ['away_sent_at' => now(), 'greeted_at' => $chat->greeted_at ?: now()] : ['greeted_at' => now()])->save();
        SendAiReplyJob::dispatch($out->id, $instance->id, $contact->id)->onQueue('disparos');

        return $kind;
    }

    /** The AI agent that would answer this number automatically, if any. */
    private function answeringAgent(User $owner, $instance)
    {
        if (!$owner->ai_enabled || $owner->ai_mode !== 'auto') {
            return null;
        }

        return app(AiProfileResolver::class)->forInstance($owner, $instance);
    }

    /** {{name}} {{first_name}} {{company}} {{today}} {{hours}} ; an unknown {{word}} is left as typed. */
    public function render(string $text, Chat $chat, User $owner, ServiceHours $cfg): string
    {
        $name = trim((string) ($chat->contact?->name ?: $chat->name));
        if ($name !== '' && str_starts_with($name, '+')) {
            $name = '';
        }
        $vars = [
            'name' => $name, 'first_name' => $name !== '' ? (preg_split('/\s+/u', $name)[0] ?? '') : '',
            'company' => $owner->name, 'today' => now()->format('d/m/Y'), 'hours' => $cfg->business_start . '–' . $cfg->business_end,
        ];

        return trim(preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', fn ($m) => $vars[strtolower($m[1])] ?? $m[0], $text));
    }
}
