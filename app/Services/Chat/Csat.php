<?php

namespace App\Services\Chat;

use App\Jobs\SendChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\CsatSetting;
use App\Models\CsatSurvey;
use App\Models\User;
use App\Services\Channels\ReplyWindow;

/** Closing a conversation, the satisfaction question that follows and capturing the customer's numeric answer. */
class Csat
{
    public function close(Chat $chat, User $by): ?CsatSurvey
    {
        if ($chat->closed_at) {
            return null;
        }
        $chat->forceFill(['closed_at' => now(), 'closed_by' => $by->id])->save();
        app(\App\Services\Bots\TypebotBot::class)->reset($chat);
        app(\App\Services\Webhooks\OutboundWebhooks::class)->emit('conversation.closed', $chat->user_id, ['chat' => $chat]);

        return $this->ask($chat);
    }

    public function reopen(Chat $chat): void
    {
        $chat->forceFill(['closed_at' => null, 'closed_by' => null])->save();
    }

    /** Sends the question when the survey is on, the customer wrote at least once and the window allows an answer. */
    private function ask(Chat $chat): ?CsatSurvey
    {
        $cfg = CsatSetting::for($chat->user_id);
        $chat->loadMissing('instance');
        if (!$cfg->enabled || !$chat->instance) {
            return null;
        }
        if (!ChatMessage::where('chat_id', $chat->id)->where('from_me', false)->exists() || !ReplyWindow::isOpen($chat)) {
            return null;
        }
        $recent = CsatSurvey::where('chat_id', $chat->id)->where('status', 'sent')->where('sent_at', '>', now()->subHours(max(1, $cfg->expire_hours)))->exists();
        if ($recent) {
            return null;
        }

        $this->say($chat, $cfg->questionText());

        return CsatSurvey::create(['user_id' => $chat->user_id, 'chat_id' => $chat->id, 'agent_id' => $chat->assigned_to, 'scale' => $cfg->scale, 'status' => 'sent', 'sent_at' => now()]);
    }

    /** True when the inbound message was the answer (a lone number in range) to an open survey. */
    public function capture(Chat $chat, ChatMessage $m): bool
    {
        $survey = CsatSurvey::where('chat_id', $chat->id)->where('status', 'sent')->latest('id')->first();
        if (!$survey) {
            return false;
        }
        $cfg = CsatSetting::for($chat->user_id);
        if ($survey->sent_at->lt(now()->subHours(max(1, $cfg->expire_hours)))) {
            $survey->update(['status' => 'expired']);

            return false;
        }
        $text = trim((string) $m->body);
        if (!preg_match('/^\d{1,2}$/', $text)) {
            return false;
        }
        [$min, $max] = $survey->scale === 'nps' ? [0, 10] : [1, 5];
        $score = (int) $text;
        if ($score < $min || $score > $max) {
            return false;
        }
        $survey->update(['status' => 'answered', 'score' => $score, 'answered_at' => now()]);
        app(\App\Services\Webhooks\OutboundWebhooks::class)->emit('survey.answered', $chat->user_id, ['chat' => $chat, 'score' => $score, 'scale' => $survey->scale]);
        $this->say($chat, $cfg->thanksText());

        return true;
    }

    private function say(Chat $chat, string $text): void
    {
        $message = ChatMessage::create([
            'chat_id' => $chat->id, 'instance_id' => $chat->instance_id, 'user_id' => $chat->user_id, 'from_me' => true,
            'source' => 'survey', 'type' => 'text', 'body' => $text, 'status' => 'pending', 'message_timestamp' => now(),
        ]);
        app(ChatMessageRecorder::class)->touchChat($chat, $message, true);
        SendChatMessageJob::dispatch($message->id)->onQueue('default');
    }

    /** @return array{responses:int,average:?float,satisfied:?int,nps:?int,sent:int,by_agent:array} */
    public static function stats(int $userId, string $scale, int $days = 30): array
    {
        $from = now()->subDays($days);
        $all = CsatSurvey::where('user_id', $userId)->where('scale', $scale)->where('sent_at', '>=', $from)->get();
        $done = $all->where('status', 'answered');
        $n = $done->count();
        $avg = $n ? round($done->avg('score'), 2) : null;
        $sat = $nps = null;
        if ($n) {
            $sat = (int) round($done->where('score', '>=', 4)->count() / $n * 100);
            $nps = (int) round(($done->where('score', '>=', 9)->count() - $done->where('score', '<=', 6)->count()) / $n * 100);
        }
        $by = $done->groupBy('agent_id')->map(fn ($g) => ['n' => $g->count(), 'avg' => round($g->avg('score'), 2)])->all();

        return ['responses' => $n, 'average' => $avg, 'satisfied' => $scale === 'csat' ? $sat : null, 'nps' => $scale === 'nps' ? $nps : null, 'sent' => $all->count(), 'by_agent' => $by];
    }
}
