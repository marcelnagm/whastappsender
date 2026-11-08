<?php

namespace App\Services\Bots;

use App\Jobs\SendChatMessageJob;
use App\Jobs\TypebotTurnJob;
use App\Models\AiSession;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatNote;
use App\Models\ServiceHours;
use App\Models\TypebotSession;
use App\Models\TypebotSetting;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Services\AI\AiBusinessHours;
use App\Services\Automation\Automations;
use App\Services\Chat\ChatAssignment;
use App\Services\Chat\ChatMessageRecorder;
use Illuminate\Support\Facades\Log;

/**
 * Typebot as a conversation bot on every channel: the customer's messages are relayed to the published flow, its answers are
 * sent back through the normal chat pipeline, and a person can take over at any moment (keyword, a block of the flow that
 * calls the handoff address, the end of the flow, or simply a team member answering).
 */
class TypebotBot
{
    public const UNSUPPORTED_INPUTS = ['file input', 'payment input', 'cards input'];

    public function __construct(private TypebotClient $client)
    {
    }

    // ---------- entry points ----------

    /** Called for each customer message. True when the bot owns the conversation (the rest of the inbound hooks should not greet or assign). */
    public function onInbound(Chat $chat, User $owner, ChatMessage $m): bool
    {
        $cfg = TypebotSetting::where('user_id', $owner->id)->first();
        if (!$cfg || !$cfg->enabled || !$cfg->public_id || trim((string) $m->body) === '') {
            return false;
        }
        if ($cfg->instance_id && (int) $cfg->instance_id !== (int) $chat->instance_id) {
            return false;
        }
        $session = TypebotSession::where('chat_id', $chat->id)->first();
        $running = $session && $session->status === 'active' && !$this->expired($session, $cfg);
        if ($session && $session->status !== 'active' && !$this->expired($session, $cfg)) {
            return false;            // finished or handed over recently: do not start the flow again on the next "thanks"
        }
        if (!$running) {
            // a new run only for conversations nobody is handling
            if ($chat->assigned_to || $this->paused($chat)) {
                return false;
            }
            if ($cfg->only_outside_hours && AiBusinessHours::isOpen(ServiceHours::for($owner->id))) {
                return false;
            }
        } elseif ($this->paused($chat)) {
            return false;
        }
        TypebotTurnJob::dispatch($chat->id, $m->id)->onQueue('default');

        return true;
    }

    /** Is the bot answering this conversation right now? (the AI agent stays quiet meanwhile) */
    public static function isActive(int $userId, ?int $contactId, ?int $instanceId): bool
    {
        if (!$contactId || !$instanceId) {
            return false;
        }
        $chat = Chat::where('user_id', $userId)->where('contact_id', $contactId)->where('instance_id', $instanceId)->first();

        return $chat && TypebotSession::where('chat_id', $chat->id)->where('status', 'active')->exists();
    }

    /** A team member answered from the CRM: the bot steps aside. */
    public function humanReplied(Chat $chat): void
    {
        TypebotSession::where('chat_id', $chat->id)->where('status', 'active')->update(['status' => 'handoff', 'last_activity_at' => now()]);
    }

    /** Closing the conversation ends the flow for good: the next message starts a new one. */
    public function reset(Chat $chat): void
    {
        TypebotSession::where('chat_id', $chat->id)->delete();
    }

    // ---------- one turn ----------

    public function turn(Chat $chat, ChatMessage $m): void
    {
        $owner = User::find($chat->user_id);
        $cfg = TypebotSetting::where('user_id', $chat->user_id)->first();
        if (!$owner || !$cfg || !$cfg->enabled) {
            return;
        }
        $text = trim((string) $m->body);
        $session = TypebotSession::firstOrNew(['chat_id' => $chat->id], ['user_id' => $chat->user_id]);

        try {
            $running = $session->exists && $session->status === 'active' && $session->session_id && !$this->expired($session, $cfg);
            if ($this->wantsHuman($cfg, $text)) {
                $this->handoff($chat, $owner, __('The customer asked for a person.'));

                return;
            }
            if ($running) {
                $answer = $this->mapChoice($session, $text);
                try {
                    $res = $this->client->continue($cfg, $session->session_id, $answer);
                } catch (TypebotSessionGone $e) {
                    $res = $this->restart($cfg, $chat, $owner, $session);
                }
            } else {
                $res = $this->restart($cfg, $chat, $owner, $session);
            }
            $this->deliver($chat, $owner, $cfg, $session, $res);
        } catch (\Throwable $e) {
            Log::warning('Typebot turn failed: ' . $e->getMessage(), ['chat_id' => $chat->id]);
            // never leave the customer talking to a wall
            $this->handoff($chat, $owner, __('The bot is unavailable.'));
        }
    }

    private function restart(TypebotSetting $cfg, Chat $chat, User $owner, TypebotSession $session): array
    {
        $name = trim((string) ($chat->contact?->name ?: $chat->name));
        $res = $this->client->start($cfg, [
            'name' => $name, 'first_name' => $name !== '' ? (preg_split('/\s+/u', $name)[0] ?? '') : '', 'phone' => (string) ($chat->phone ?? ''),
            'chat_id' => (string) $chat->id, 'handoff_url' => self::handoffUrl($chat),
        ]);
        $session->fill(['user_id' => $chat->user_id, 'session_id' => $res['sessionId'] ?? null, 'status' => 'active', 'choices' => null])->save();

        return $res;
    }

    private function deliver(Chat $chat, User $owner, TypebotSetting $cfg, TypebotSession $session, array $res): void
    {
        $lines = [];
        foreach ((array) ($res['messages'] ?? []) as $msg) {
            if ($t = $this->bubbleText($msg)) {
                $lines[] = $t;
            }
        }
        foreach ((array) ($res['clientSideActions'] ?? []) as $a) {
            if (!empty($a['redirect']['url'])) {
                $lines[] = (string) $a['redirect']['url'];
            }
        }

        $input = $res['input'] ?? null;
        $choices = null;
        $unsupported = false;
        if ($input) {
            $type = (string) ($input['type'] ?? '');
            if (in_array($type, self::UNSUPPORTED_INPUTS, true)) {
                $unsupported = true;
            } elseif (!empty($input['items'])) {
                $choices = collect($input['items'])->map(fn ($i) => trim((string) ($i['content'] ?? $i['title'] ?? '')))->filter()->values()->all();
                if ($choices) {
                    $lines[] = collect($choices)->map(fn ($c, $i) => ($i + 1) . ') ' . $c)->implode("\n");
                }
            }
        }

        foreach ($lines as $i => $line) {
            $this->say($chat, $line, $i);
        }
        $session->forceFill(['choices' => $choices, 'last_activity_at' => now()])->save();

        if ($unsupported) {
            $this->handoff($chat, $owner, __('The flow asked for something that does not work in chat (file or payment).'));
        } elseif (!$input) {
            $session->forceFill(['status' => 'finished'])->save();
            if ($cfg->on_finish === 'handoff') {
                $this->handoff($chat, $owner, __('The flow ended.'));
            }
        }
    }

    // ---------- handoff ----------

    /** A person takes the conversation: the bot stops, the AI agent is paused, the chat is distributed and the team is told. */
    public function handoff(Chat $chat, User $owner, string $reason = '', ?string $note = null): void
    {
        $cfg = TypebotSetting::where('user_id', $owner->id)->first();
        $session = TypebotSession::firstOrNew(['chat_id' => $chat->id], ['user_id' => $owner->id]);
        if ($session->exists && $session->status === 'handoff') {
            return;                                   // already handed over: never twice
        }
        $session->fill(['user_id' => $owner->id, 'status' => 'handoff', 'choices' => null, 'last_activity_at' => now()])->save();

        if ($chat->contact_id && $chat->instance_id) {
            $s = AiSession::firstOrCreate(['user_id' => $owner->id, 'contact_id' => $chat->contact_id, 'instance_id' => $chat->instance_id], ['status' => 'active', 'human_handoff' => false]);
            $s->update(['human_handoff' => true]);
        }
        if ($cfg && trim((string) $cfg->handoff_message) !== '') {
            $this->say($chat, trim($cfg->handoff_message), 0);
        }
        $assign = app(ChatAssignment::class);
        $chat = $chat->fresh();
        if ($cfg && $cfg->handoff_to && !$chat->assigned_to) {
            try {
                $assign->assign($chat, (int) $cfg->handoff_to);
            } catch (\Throwable $e) {
                Log::info('Typebot handoff_to is not in the team: ' . $e->getMessage());
            }
        } else {
            $assign->autoAssign($chat, $owner);
        }
        $chat = $chat->fresh();

        $body = '🤖 ' . __('Bot handoff') . ($reason !== '' ? ': ' . $reason : '') . ($note ? "\n" . mb_substr($note, 0, 500) : '');
        ChatNote::create(['user_id' => $owner->id, 'chat_id' => $chat->id, 'author_id' => $owner->id, 'body' => $body]);

        $label = $chat->name ?: ($chat->phone ?: __('Conversation'));
        $to = ($chat->assigned_to ? User::find($chat->assigned_to) : null) ?: $owner;
        $to->notify(new AutomationNotice(__('A customer needs a person: :name', ['name' => $label]), route('chat.index', ['chat' => $chat->id])));
        app(Automations::class)->fire('bot.handoff', $owner, ['chat' => $chat, 'text' => $reason]);
    }

    // ---------- helpers ----------

    /** Signed address the flow calls (HTTP Request block) to hand over; no login needed, the signature is the key. */
    public static function handoffUrl(Chat $chat): string
    {
        return url('/api/public/typebot/handoff/' . $chat->id . '/' . self::signature($chat->id));
    }

    public static function signature(int $chatId): string
    {
        return substr(hash_hmac('sha256', 'typebot-handoff:' . $chatId, (string) config('app.key')), 0, 32);
    }

    private function say(Chat $chat, string $text, int $order): void
    {
        $message = ChatMessage::create([
            'chat_id' => $chat->id, 'instance_id' => $chat->instance_id, 'user_id' => $chat->user_id, 'from_me' => true,
            'source' => 'bot', 'type' => 'text', 'body' => $text, 'status' => 'pending', 'message_timestamp' => now()->addSeconds($order),
        ]);
        app(ChatMessageRecorder::class)->touchChat($chat, $message, true);
        SendChatMessageJob::dispatch($message->id)->onQueue('default')->delay(now()->addSeconds($order * 2));
    }

    private function wantsHuman(TypebotSetting $cfg, string $text): bool
    {
        $t = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)) . ' ';
        foreach ($cfg->keywords() as $k) {
            $k = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $k));
            if ($k !== '' && str_contains($t, ' ' . $k . ' ')) {
                return true;
            }
        }

        return false;
    }

    /** "2" or "1,3" answers a numbered list of options. */
    private function mapChoice(TypebotSession $s, string $text): string
    {
        $choices = (array) $s->choices;
        if (!$choices || !preg_match('/^\d+(\s*,\s*\d+)*$/', $text)) {
            return $text;
        }
        $picked = collect(preg_split('/\s*,\s*/', $text))->map(fn ($n) => $choices[(int) $n - 1] ?? null);

        return $picked->contains(null) ? $text : $picked->implode(', ');
    }

    private function expired(TypebotSession $s, TypebotSetting $cfg): bool
    {
        return $s->last_activity_at && $s->last_activity_at->lt(now()->subMinutes(max(5, $cfg->expire_minutes)));
    }

    private function paused(Chat $chat): bool
    {
        if (!$chat->contact_id || !$chat->instance_id) {
            return false;
        }

        return (bool) AiSession::where('user_id', $chat->user_id)->where('contact_id', $chat->contact_id)->where('instance_id', $chat->instance_id)->value('human_handoff');
    }

    /** One bubble as chat text; media become their address (every channel previews links). */
    private function bubbleText(array $msg): ?string
    {
        $c = (array) ($msg['content'] ?? []);
        $type = $msg['type'] ?? '';
        if ($type === 'text') {
            $t = isset($c['markdown']) ? (string) $c['markdown'] : $this->richText($c['richText'] ?? []);
            $t = preg_replace('/\*\*(.+?)\*\*/su', '*$1*', $t);                 // markdown bold -> chat bold
            $t = preg_replace('/\[([^\]]+)\]\((https?:[^)\s]+)\)/u', '$1: $2', $t);

            return trim($t) !== '' ? trim($t) : null;
        }
        if (in_array($type, ['image', 'video', 'audio', 'embed'], true) && !empty($c['url'])) {
            return (string) $c['url'];
        }

        return null;
    }

    private function richText($nodes): string
    {
        $out = '';
        foreach ((array) $nodes as $n) {
            if (isset($n['text'])) {
                $out .= $n['text'];
            } elseif (!empty($n['children'])) {
                $out .= $this->richText($n['children']) . (in_array($n['type'] ?? '', ['p', 'li'], true) ? "\n" : '');
            }
        }

        return $out;
    }
}
