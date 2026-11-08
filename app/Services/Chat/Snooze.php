<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\User;
use App\Notifications\AutomationNotice;
use Illuminate\Support\Carbon;

/**
 * "Remind me later": a snoozed conversation leaves the inbox until the time comes or the customer writes again.
 * When the time comes the person in charge (or the owner) is notified and the conversation is back in the list.
 */
class Snooze
{
    /** Quick choices of the chat panel. */
    public static function presets(): array
    {
        return ['1h' => __('In 1 hour'), '3h' => __('In 3 hours'), 'tomorrow' => __('Tomorrow 9:00'), 'week' => __('Next week')];
    }

    public function until(string $preset, ?Carbon $now = null): ?Carbon
    {
        $now = $now ?: now();

        return match ($preset) {
            '1h' => $now->copy()->addHour(),
            '3h' => $now->copy()->addHours(3),
            'tomorrow' => $now->copy()->addDay()->setTime(9, 0),
            'week' => $now->copy()->next(Carbon::MONDAY)->setTime(9, 0),
            default => null,
        };
    }

    public function snooze(Chat $chat, Carbon $until): void
    {
        $chat->forceFill(['snoozed_until' => $until])->save();
    }

    public function wake(Chat $chat): void
    {
        if ($chat->snoozed_until) {
            $chat->forceFill(['snoozed_until' => null])->save();
        }
    }

    /** Conversations whose time came: back to the list, person in charge notified. @return int how many woke */
    public function run(?Carbon $now = null): int
    {
        $now = $now ?: now();
        $n = 0;
        Chat::whereNotNull('snoozed_until')->where('snoozed_until', '<=', $now)->orderBy('id')->each(function (Chat $chat) use (&$n) {
            $this->wake($chat);
            $to = ($chat->assigned_to ? User::find($chat->assigned_to) : null) ?: User::find($chat->user_id);
            if ($to) {
                $to->notify(new AutomationNotice(__('Snoozed conversation is back: :name', ['name' => $chat->displayName()]), route('chat.index')));
            }
            $n++;
        });

        return $n;
    }
}
