<?php

namespace App\Services\Sla;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ServiceHours;
use App\Models\SlaEvent;
use App\Models\SlaSetting;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Services\AI\AiBusinessHours;
use App\Services\Chat\ChatAssignment;
use Carbon\Carbon;

/**
 * First-response SLA: a customer message nobody answered within the limit raises an alert to the
 * assignee (or the owner); after some more minutes it is escalated (and optionally reassigned).
 * An answer is any outbound message that is not a campaign.
 */
class SlaMonitor
{
    /** @return array{breaches:int,escalations:int} */
    public function run(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $out = ['breaches' => 0, 'escalations' => 0];

        foreach (SlaSetting::where('enabled', true)->get() as $cfg) {
            $owner = User::find($cfg->user_id);
            if (!$owner) {
                continue;
            }
            $this->resolve($cfg->user_id, $now);
            foreach ($this->waiting($cfg->user_id) as $row) {
                $waited = $this->waitedMinutes($cfg, $row['since'], $now);
                if ($waited >= $cfg->first_response_minutes && $this->record($cfg, $row, 'breach', $waited)) {
                    $this->notifyBreach($owner, $row['chat'], $waited);
                    $out['breaches']++;
                }
                if ($waited >= $cfg->first_response_minutes + $cfg->escalate_after_minutes && $this->record($cfg, $row, 'escalation', $waited)) {
                    $this->escalate($cfg, $owner, $row['chat'], $waited);
                    $out['escalations']++;
                }
            }
        }

        return $out;
    }

    /** Conversations whose newest customer message(s) have no answer yet. @return array<int,array{chat:Chat,message_id:int,since:Carbon}> */
    public function waiting(int $userId): array
    {
        $chats = Chat::where('user_id', $userId)->where('archived', false)->where('last_message_from_me', false)->whereNotNull('last_message_at')->get();
        $rows = [];
        foreach ($chats as $chat) {
            $lastAnswer = ChatMessage::where('chat_id', $chat->id)->where('from_me', true)->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'campaign'))->max('id');
            $first = ChatMessage::where('chat_id', $chat->id)->where('from_me', false)->when($lastAnswer, fn ($q) => $q->where('id', '>', $lastAnswer))->orderBy('id')->first();
            if (!$first) {
                continue;
            }
            $rows[] = ['chat' => $chat, 'message_id' => (int) $first->id, 'since' => Carbon::parse($first->message_timestamp ?? $first->created_at)];
        }

        return $rows;
    }

    /** Minutes the customer has been waiting; only opening hours count when the setting says so. */
    public function waitedMinutes(SlaSetting $cfg, Carbon $since, Carbon $now): int
    {
        if ($now->lte($since)) {
            return 0;
        }
        if (!$cfg->business_only) {
            return (int) $since->diffInMinutes($now);
        }
        $hours = ServiceHours::for($cfg->user_id);
        $tz = $hours->timezone ?: config('ai.business_hours.timezone');
        $days = AiBusinessHours::days($hours->business_days);
        [$sh, $sm] = array_map('intval', explode(':', $hours->business_start ?: '09:00'));
        [$eh, $em] = array_map('intval', explode(':', $hours->business_end ?: '18:00'));

        $from = $since->copy()->setTimezone($tz);
        $to = $now->copy()->setTimezone($tz);
        $total = 0;
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            if (!in_array($day->dayOfWeekIso, $days, true)) {
                continue;
            }
            $open = $day->copy()->setTime($sh, $sm);
            $close = $day->copy()->setTime($eh, $em);
            $a = $open->max($from);
            $b = $close->min($to);
            if ($b->gt($a)) {
                $total += (int) $a->diffInMinutes($b);
            }
        }

        return $total;
    }

    /** Marks open alerts as resolved once the customer got an answer (or the chat is archived). */
    private function resolve(int $userId, Carbon $now): void
    {
        $waitingIds = collect($this->waiting($userId))->pluck('message_id')->all();
        SlaEvent::where('user_id', $userId)->whereNull('resolved_at')->get()->each(function (SlaEvent $e) use ($waitingIds, $now) {
            if (!in_array((int) $e->inbound_message_id, $waitingIds, true)) {
                $e->forceFill(['resolved_at' => $now])->save();
            }
        });
    }

    private function record(SlaSetting $cfg, array $row, string $kind, int $waited): bool
    {
        $event = SlaEvent::firstOrCreate(
            ['chat_id' => $row['chat']->id, 'inbound_message_id' => $row['message_id'], 'kind' => $kind],
            ['user_id' => $cfg->user_id, 'assigned_to' => $row['chat']->assigned_to, 'waited_minutes' => $waited],
        );

        return $event->wasRecentlyCreated;
    }

    private function label(Chat $chat): string
    {
        return $chat->name ?: ($chat->phone ?: __('Conversation'));
    }

    private function notifyBreach(User $owner, Chat $chat, int $waited): void
    {
        $target = $chat->assigned_to ? (User::find($chat->assigned_to) ?: $owner) : $owner;
        $target->notify(new AutomationNotice(__('No answer for :min min: :name', ['min' => $waited, 'name' => $this->label($chat)]), route('chat.index', ['chat' => $chat->id])));
    }

    private function escalate(SlaSetting $cfg, User $owner, Chat $chat, int $waited): void
    {
        $to = $cfg->escalate_to ? app(ChatAssignment::class)->people($owner)->firstWhere('id', $cfg->escalate_to) : null;
        $to = $to ?: $owner;
        if ($cfg->reassign && $chat->assigned_to !== $to->id) {
            app(ChatAssignment::class)->assign($chat, $to->id);
        }
        $to->notify(new AutomationNotice(__('Escalated: :name waits :min min without an answer', ['min' => $waited, 'name' => $this->label($chat)]), route('chat.index', ['chat' => $chat->id])));
    }
}
