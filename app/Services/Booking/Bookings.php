<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\Contact;
use App\Models\ServiceHours;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Services\AI\AiBusinessHours;
use App\Services\Contacts\ContactDuplicates;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Self-service meeting booking: free slots come from the opening hours, minus what is already booked. */
class Bookings
{
    /** Free start times (UTC Carbon) of one local day. @return array<int,Carbon> */
    public function slots(BookingSetting $cfg, Carbon $day, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $hours = ServiceHours::for($cfg->user_id);
        $tz = $hours->timezone ?: config('ai.business_hours.timezone');
        $local = Carbon::parse($day->format('Y-m-d'), $tz)->startOfDay();

        if (!in_array($local->dayOfWeekIso, AiBusinessHours::days($hours->business_days), true)) {
            return [];
        }
        if ($local->gt($now->copy()->setTimezone($tz)->addDays($cfg->max_days)->endOfDay())) {
            return [];
        }
        [$sh, $sm] = array_map('intval', explode(':', $hours->business_start ?: '09:00'));
        [$eh, $em] = array_map('intval', explode(':', $hours->business_end ?: '18:00'));
        $start = $local->copy()->setTime($sh, $sm);
        $end = $local->copy()->setTime($eh, $em);
        $earliest = $now->copy()->addHours($cfg->min_notice_hours);

        $taken = Booking::where('user_id', $cfg->user_id)->where('status', 'confirmed')->where('ends_at', '>', $start->copy()->utc())->where('starts_at', '<', $end->copy()->utc())->get();
        $out = [];
        for ($t = $start->copy(); $t->copy()->addMinutes($cfg->duration_minutes)->lte($end); $t->addMinutes($cfg->duration_minutes)) {
            $s = $t->copy()->utc();
            $e = $s->copy()->addMinutes($cfg->duration_minutes);
            if ($s->lt($earliest)) {
                continue;
            }
            if ($taken->contains(fn ($b) => $b->starts_at->lt($e) && $b->ends_at->gt($s))) {
                continue;
            }
            $out[] = $s;
        }

        return $out;
    }

    /** Books the slot or returns null when it was taken meanwhile / is not offered. */
    public function book(BookingSetting $cfg, string $name, string $phone, ?string $email, Carbon $startsAt): ?Booking
    {
        $startsAt = $startsAt->copy()->utc();
        $tz = ServiceHours::for($cfg->user_id)->timezone ?: config('ai.business_hours.timezone');

        return DB::transaction(function () use ($cfg, $name, $phone, $email, $startsAt, $tz) {
            // lock the owner row so two people cannot take the same time together
            User::whereKey($cfg->user_id)->lockForUpdate()->first();
            $offered = collect($this->slots($cfg, $startsAt->copy()->setTimezone($tz)))->contains(fn ($s) => $s->equalTo($startsAt));
            if (!$offered) {
                return null;
            }
            $contact = $this->contactFor($cfg->user_id, $name, $phone, $email);
            $end = $startsAt->copy()->addMinutes($cfg->duration_minutes);
            $task = Task::create(['user_id' => $cfg->user_id, 'title' => $cfg->title . ': ' . $name, 'notes' => trim($phone . ' ' . $email), 'due_at' => $startsAt, 'assigned_to' => $cfg->user_id, 'created_by' => $cfg->user_id, 'contact_id' => $contact?->id]);
            $b = Booking::create(['user_id' => $cfg->user_id, 'contact_id' => $contact?->id, 'task_id' => $task->id, 'name' => $name, 'phone' => $phone, 'email' => $email,
                'starts_at' => $startsAt, 'ends_at' => $end, 'status' => 'confirmed', 'token' => Str::random(32)]);
            User::find($cfg->user_id)?->notify(new AutomationNotice(__('New booking: :name on :when', ['name' => $name, 'when' => $startsAt->copy()->setTimezone($tz)->format('d/m H:i')]), route('bookings.index')));

            return $b;
        });
    }

    public function cancel(Booking $b): void
    {
        if ($b->status === 'canceled') {
            return;
        }
        $b->update(['status' => 'canceled']);
        if ($b->task_id) {
            Task::whereKey($b->task_id)->whereNull('done_at')->delete();
        }
    }

    private function contactFor(int $userId, string $name, string $phone, ?string $email): ?Contact
    {
        $key = ContactDuplicates::phoneKey($phone);
        if (!$key) {
            return null;
        }
        $found = Contact::where('user_id', $userId)->get(['id', 'contact'])->first(fn ($c) => ContactDuplicates::phoneKey($c->contact) === $key);
        if ($found) {
            return Contact::find($found->id);
        }

        return Contact::create(['user_id' => $userId, 'contact' => $key, 'name' => $name, 'email' => $email, 'status' => 'ativo']);
    }
}
