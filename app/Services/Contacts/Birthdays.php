<?php

namespace App\Services\Contacts;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\User;
use App\Services\Automation\Automations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Birthdays: a daily run fires the automation trigger "contact.birthday" (and the webhook event) once a year per
 * person, so the account decides what happens (send a message, tag, create a task). A list shows the next ones.
 */
class Birthdays
{
    /** Fires for everybody whose birthday is today. Feb 29 is celebrated on Feb 28 in common years. @return int people reached */
    public function run(?Carbon $today = null): int
    {
        $today = $today ?: now();
        $n = 0;
        $q = Contact::whereNotNull('birthday')->where(fn ($w) => $w->whereNull('birthday_notified_year')->orWhere('birthday_notified_year', '<', $today->year));
        $this->dayMatch($q, $today)->orderBy('id')->each(function (Contact $c) use ($today, &$n) {
            Contact::whereKey($c->id)->toBase()->update(['birthday_notified_year' => $today->year]);
            $owner = User::find($c->user_id);
            if (!$owner || $c->ignore_me) {
                return;
            }
            $ctx = ['contact' => $c];
            if ($chat = Chat::where('user_id', $c->user_id)->where('contact_id', $c->id)->orderByDesc('last_message_at')->first()) {
                $ctx['chat'] = $chat;
            }
            app(Automations::class)->fire('contact.birthday', $owner, $ctx);
            $n++;
        });

        return $n;
    }

    private function dayMatch($q, Carbon $today)
    {
        $md = fn (Carbon $d) => $d->format('m-d');
        $days = [$md($today)];
        if ($today->format('m-d') === '02-28' && !$today->isLeapYear()) {
            $days[] = '02-29';
        }
        // portable month-day match (SQLite and MySQL)
        $driver = $q->getConnection()->getDriverName();
        $expr = $driver === 'sqlite' ? "strftime('%m-%d', birthday)" : "DATE_FORMAT(birthday, '%m-%d')";

        return $q->whereIn(\DB::raw($expr), $days);
    }

    /** People of the account whose birthday falls in the next N days (today included), soonest first. */
    public function upcoming(User $owner, int $days = 30, ?Carbon $today = null): Collection
    {
        $today = ($today ?: now())->copy()->startOfDay();

        return Contact::where('user_id', $owner->id)->whereNotNull('birthday')->get()->map(function (Contact $c) use ($today) {
            $next = $c->birthday->copy()->year($today->year);
            if ($c->birthday->format('m-d') === '02-29' && !$next->isLeapYear()) {
                $next = Carbon::create($today->year, 2, 28);
            }
            if ($next->lt($today)) {
                $next = $c->birthday->copy()->year($today->year + 1);
                if ($c->birthday->format('m-d') === '02-29' && !$next->isLeapYear()) {
                    $next = Carbon::create($today->year + 1, 2, 28);
                }
            }

            return ['contact' => $c, 'date' => $next, 'in_days' => $today->diffInDays($next), 'turns' => $next->year - $c->birthday->year];
        })->filter(fn ($r) => $r['in_days'] <= $days)->sortBy('in_days')->values();
    }
}
