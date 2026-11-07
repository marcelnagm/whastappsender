<?php

namespace App\Services\Chat;

use App\Jobs\SendChatMessageJob;
use App\Models\AiSession;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Services\Channels\ReplyWindow;
use Illuminate\Support\Carbon;

/** Messages typed now and sent later, in the name of the person who scheduled them. */
class ScheduledMessages
{
    public const MAX_PENDING = 100;
    public const MAX_DAYS = 90;

    public function schedule(Chat $chat, User $by, string $body, Carbon $at, bool $cancelOnReply = true): ScheduledMessage
    {
        $body = trim($body);
        abort_if($body === '', 422, __('Type a message'));
        abort_if($at->lt(now()->addMinute()), 422, __('Choose a time at least one minute from now.'));
        abort_if($at->gt(now()->addDays(self::MAX_DAYS)), 422, __('Choose a time within :n days.', ['n' => self::MAX_DAYS]));
        abort_if(ScheduledMessage::where('user_id', $chat->user_id)->where('status', 'pending')->count() >= self::MAX_PENDING, 422, __('Limit of :n scheduled messages reached.', ['n' => self::MAX_PENDING]));

        return ScheduledMessage::create(['user_id' => $chat->user_id, 'chat_id' => $chat->id, 'created_by' => $by->id, 'body' => mb_substr($body, 0, 4096),
            'send_at' => $at, 'cancel_on_reply' => $cancelOnReply, 'status' => 'pending']);
    }

    public function cancel(ScheduledMessage $m): void
    {
        if ($m->status === 'pending') {
            $m->update(['status' => 'canceled', 'note' => __('Canceled by a person')]);
        }
    }

    /** Sends what fell due. Returns how many went out. */
    public function dispatchDue(int $limit = 200): int
    {
        $sent = 0;
        foreach (ScheduledMessage::where('status', 'pending')->where('send_at', '<=', now())->orderBy('send_at')->limit($limit)->get() as $m) {
            // claim it first so two workers never send the same message
            if (ScheduledMessage::where('id', $m->id)->where('status', 'pending')->update(['status' => 'sending']) !== 1) {
                continue;
            }
            try {
                $sent += $this->send($m) ? 1 : 0;
            } catch (\Throwable $e) {
                $m->update(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        return $sent;
    }

    private function send(ScheduledMessage $m): bool
    {
        $chat = Chat::with('instance')->where('user_id', $m->user_id)->find($m->chat_id);
        if (!$chat || !$chat->instance) {
            $m->update(['status' => 'failed', 'note' => __('The conversation no longer exists.')]);

            return false;
        }
        if ($m->cancel_on_reply && ChatMessage::where('chat_id', $chat->id)->where('from_me', false)->where('message_timestamp', '>', $m->created_at)->exists()) {
            $m->update(['status' => 'canceled', 'note' => __('The customer replied first.')]);

            return false;
        }
        if (!ReplyWindow::isOpen($chat)) {
            $m->update(['status' => 'failed', 'note' => ReplyWindow::closedMessage($chat->instance->channelKey())]);

            return false;
        }

        $author = User::find($m->created_by);
        $message = ChatMessage::create([
            'chat_id' => $chat->id, 'instance_id' => $chat->instance_id, 'user_id' => $chat->user_id, 'from_me' => true,
            'sent_by' => $author?->id, 'source' => 'human', 'type' => 'text', 'body' => ChatSignature::apply($author?->chat_signature, $m->body),
            'status' => 'pending', 'message_timestamp' => now(),
        ]);
        app(ChatMessageRecorder::class)->touchChat($chat, $message, true);

        if (config('chat.pause_ai_on_reply') && $chat->contact_id) {
            AiSession::where('user_id', $chat->user_id)->where('contact_id', $chat->contact_id)->where('instance_id', $chat->instance_id)->update(['human_handoff' => true]);
        }
        SendChatMessageJob::dispatch($message->id)->onQueue('default');
        $m->update(['status' => 'sent', 'sent_at' => now(), 'message_id' => $message->id]);

        return true;
    }
}
