<?php

namespace App\Services\Pipeline;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\Automations;
use App\Services\Contacts\Lifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Subscriptions: a won deal with a billing cycle renews on a date. Some days before it the person in charge gets a
 * task (and the automation "deal.renewal" fires); the team then marks it renewed (next date) or cancelled (churn).
 * MRR/ARR are the monthly / yearly value of the active subscriptions.
 */
class Renewals
{
    public const CYCLES = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];
    public const NOTICE_DAYS = 15;

    public static function labels(): array
    {
        return ['monthly' => __('Monthly'), 'quarterly' => __('Quarterly'), 'yearly' => __('Yearly')];
    }

    public static function next(Carbon $from, string $cycle): Carbon
    {
        return $from->copy()->addMonthsNoOverflow(self::CYCLES[$cycle] ?? 1);
    }

    /** Active subscriptions of the account. */
    private function active(User $owner)
    {
        return Deal::where('user_id', $owner->id)->where('status', 'won')->whereNotNull('billing_cycle')->whereNull('churned_at');
    }

    /** @return array{mrr:float,arr:float,count:int,churned:int} */
    public function summary(User $owner): array
    {
        $mrr = $this->active($owner)->get()->sum(fn (Deal $d) => (float) $d->value / (self::CYCLES[$d->billing_cycle] ?? 1));

        return ['mrr' => round($mrr, 2), 'arr' => round($mrr * 12, 2), 'count' => $this->active($owner)->count(),
            'churned' => Deal::where('user_id', $owner->id)->whereNotNull('billing_cycle')->whereNotNull('churned_at')->count()];
    }

    /** Subscriptions renewing in the next N days (overdue ones included), soonest first. */
    public function upcoming(User $owner, int $days = 60, ?Carbon $today = null): Collection
    {
        $today = ($today ?: now())->copy()->startOfDay();

        return $this->active($owner)->whereNotNull('renews_at')->where('renews_at', '<=', $today->copy()->addDays($days)->toDateString())
            ->with('contact')->orderBy('renews_at')->get();
    }

    /** Sets the first renewal date when a deal is won as a subscription. */
    public function schedule(Deal $deal): void
    {
        if ($deal->status === 'won' && $deal->billing_cycle && !$deal->renews_at) {
            $deal->renews_at = self::next($deal->closed_at ?: now(), $deal->billing_cycle)->toDateString();
        }
    }

    public function renew(Deal $deal): void
    {
        $base = $deal->renews_at ? Carbon::parse($deal->renews_at) : now();
        Deal::whereKey($deal->id)->toBase()->update(['renews_at' => self::next($base, $deal->billing_cycle ?: 'monthly')->toDateString(), 'renewal_notified_at' => null, 'churned_at' => null]);
    }

    public function cancel(Deal $deal): void
    {
        Deal::whereKey($deal->id)->toBase()->update(['churned_at' => now()]);
        // the person stops being a customer only when no other subscription of theirs is active
        if ($deal->contact_id && !Deal::where('contact_id', $deal->contact_id)->where('id', '!=', $deal->id)->where('status', 'won')->whereNotNull('billing_cycle')->whereNull('churned_at')->exists()) {
            if ($c = Contact::where('user_id', $deal->user_id)->find($deal->contact_id)) {
                app(Lifecycle::class)->set($c, 'churned');
            }
        }
    }

    /** Daily: task + automation for the subscriptions renewing soon, once per renewal date. @return int how many */
    public function run(?Carbon $today = null): int
    {
        $today = ($today ?: now())->copy()->startOfDay();
        $n = 0;
        Deal::where('status', 'won')->whereNotNull('billing_cycle')->whereNull('churned_at')->whereNull('renewal_notified_at')
            ->whereNotNull('renews_at')->where('renews_at', '<=', $today->copy()->addDays(self::NOTICE_DAYS)->toDateString())->orderBy('id')
            ->each(function (Deal $d) use (&$n) {
                Deal::whereKey($d->id)->toBase()->update(['renewal_notified_at' => now()]);
                $owner = User::find($d->user_id);
                if (!$owner) {
                    return;
                }
                Task::create(['user_id' => $d->user_id, 'title' => __('Renewal') . ': ' . $d->title, 'due_at' => Carbon::parse($d->renews_at)->setTime(9, 0),
                    'assigned_to' => $d->assigned_to ?: $d->user_id, 'created_by' => $d->user_id, 'contact_id' => $d->contact_id, 'deal_id' => $d->id]);
                app(Automations::class)->fire('deal.renewal', $owner, ['deal' => $d]);
                $n++;
            });

        return $n;
    }
}
