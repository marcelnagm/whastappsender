<?php

namespace App\Services\Chat;

use App\Jobs\SendChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\Channels\ReplyWindow;

/** Multi-step follow-ups ("after 1 day, after 3 more days...") that stop by themselves when the customer answers. */
class Sequences
{
    public const MAX_STEPS = 10;

    /** @param array<int,array{delay_minutes:int|string,body:string}> $steps */
    public function save(User $owner, ?Sequence $seq, string $name, bool $active, array $steps): Sequence
    {
        $steps = array_values(array_filter($steps, fn ($s) => trim((string) ($s['body'] ?? '')) !== ''));
        abort_if(!$steps, 422, __('Add at least one step with a message.'));
        abort_if(count($steps) > self::MAX_STEPS, 422, __('At most :n steps.', ['n' => self::MAX_STEPS]));

        $seq = $seq ?: new Sequence(['user_id' => $owner->id]);
        $seq->fill(['name' => $name, 'active' => $active])->save();
        $seq->steps()->delete();
        foreach ($steps as $i => $s) {
            SequenceStep::create(['sequence_id' => $seq->id, 'position' => $i + 1, 'delay_minutes' => max(1, min(60 * 24 * 60, (int) $s['delay_minutes'])), 'body' => mb_substr(trim($s['body']), 0, 4096)]);
        }

        return $seq;
    }

    public function enroll(Chat $chat, Sequence $seq, User $by): SequenceEnrollment
    {
        abort_unless((int) $seq->user_id === (int) $chat->user_id, 403);
        abort_if(!$seq->active, 422, __('This sequence is paused.'));
        $first = $seq->steps()->first();
        abort_if(!$first, 422, __('This sequence has no steps.'));
        abort_if(SequenceEnrollment::where('chat_id', $chat->id)->where('sequence_id', $seq->id)->where('status', 'active')->exists(), 422, __('This conversation is already in the sequence.'));

        return SequenceEnrollment::create(['user_id' => $chat->user_id, 'sequence_id' => $seq->id, 'chat_id' => $chat->id, 'enrolled_by' => $by->id,
            'next_position' => 1, 'next_at' => now()->addMinutes($first->delay_minutes), 'status' => 'active']);
    }

    public function stop(SequenceEnrollment $e, string $reason): void
    {
        if ($e->status === 'active') {
            $e->update(['status' => 'stopped', 'stop_reason' => $reason, 'next_at' => null]);
        }
    }

    /** The customer wrote: every running sequence of the conversation ends. */
    public function stopOnReply(Chat $chat): void
    {
        SequenceEnrollment::where('chat_id', $chat->id)->where('status', 'active')->get()->each(fn ($e) => $this->stop($e, 'replied'));
    }

    /** Sends the steps that fell due. Returns how many messages went out. */
    public function dispatchDue(int $limit = 200): int
    {
        $sent = 0;
        foreach (SequenceEnrollment::where('status', 'active')->where('next_at', '<=', now())->orderBy('next_at')->limit($limit)->get() as $e) {
            // claim: push next_at away before sending so a parallel worker skips it
            if (SequenceEnrollment::where('id', $e->id)->where('status', 'active')->where('next_at', $e->next_at)->update(['next_at' => now()->addMinutes(10)]) !== 1) {
                continue;
            }
            try {
                $sent += $this->step($e) ? 1 : 0;
            } catch (\Throwable $ex) {
                $this->stop($e->fresh(), 'failed');
            }
        }

        return $sent;
    }

    private function step(SequenceEnrollment $e): bool
    {
        $chat = Chat::with(['instance', 'contact'])->where('user_id', $e->user_id)->find($e->chat_id);
        $seq = Sequence::find($e->sequence_id);
        $step = $seq ? SequenceStep::where('sequence_id', $seq->id)->where('position', $e->next_position)->first() : null;
        if (!$chat || !$chat->instance || !$seq || !$seq->active || !$step) {
            $this->stop($e, 'removed');

            return false;
        }
        // the customer may have written since the last step (the hook normally already ended it)
        if (ChatMessage::where('chat_id', $chat->id)->where('from_me', false)->where('message_timestamp', '>', $e->created_at)->exists()) {
            $this->stop($e, 'replied');

            return false;
        }
        if ($chat->contact && $chat->contact->consent_status === 'revoked') {
            $this->stop($e, 'opt_out');

            return false;
        }
        if (!ReplyWindow::isOpen($chat)) {
            $this->stop($e, 'window');

            return false;
        }

        $author = User::find($e->enrolled_by);
        $name = trim((string) ($chat->contact->name ?? $chat->name));
        $text = str_replace(['{{name}}', '{{first_name}}'], [$name, explode(' ', $name)[0] ?? ''], $step->body);
        $message = ChatMessage::create([
            'chat_id' => $chat->id, 'instance_id' => $chat->instance_id, 'user_id' => $chat->user_id, 'from_me' => true,
            'sent_by' => $author?->id, 'source' => 'sequence', 'type' => 'text', 'body' => ChatSignature::apply($author?->chat_signature, $text),
            'status' => 'pending', 'message_timestamp' => now(),
        ]);
        app(ChatMessageRecorder::class)->touchChat($chat, $message, true);
        SendChatMessageJob::dispatch($message->id)->onQueue('default');

        $next = SequenceStep::where('sequence_id', $seq->id)->where('position', '>', $step->position)->orderBy('position')->first();
        if ($next) {
            $e->update(['next_position' => $next->position, 'next_at' => now()->addMinutes($next->delay_minutes)]);
        } else {
            $e->update(['status' => 'completed', 'next_at' => null]);
        }

        return true;
    }
}
