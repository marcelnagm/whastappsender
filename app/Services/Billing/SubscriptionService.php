<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Notifications\BillingNotice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Subscription lifecycle and dates. Independent from any payment gateway: a gateway integration only has to
 * call markInvoicePaid() when money arrives (and may call cancel() / changePlan() on customer requests).
 *
 *   pending ──paid──► active ◄──paid── past_due ◄─ period ends unpaid ─ active
 *   trialing ─paid─►  active          │ grace_days
 *                                      ▼
 *                                  suspended (read-only) ── suspension_days ──► expired (Free)
 *   active + cancel_at_period_end ── period ends ──► canceled (Free)
 */
class SubscriptionService
{
    public function current(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)
            ->whereIn('status', Subscription::liveStatuses())
            ->orderByDesc('id')
            ->first();
    }

    public function subscribe(User $user, Plan $plan, string $cycle = 'monthly', ?Carbon $now = null): ?Subscription
    {
        $now = $now ?? now();
        $cycle = $this->cycleFor($plan, $cycle);
        $live = $this->current($user);

        if ($live) {
            return $this->changePlan($live, $plan, $cycle, $now);
        }

        if ($plan->isFree()) {
            return null; // already on Free
        }

        return DB::transaction(function () use ($user, $plan, $cycle, $now) {
            $trial = $this->trialDaysFor($user, $plan);

            if ($trial > 0) {
                $trialEnds = $now->copy()->addDays($trial);
                $sub = Subscription::create([
                    'user_id' => $user->id, 'plan_id' => $plan->id, 'cycle' => $cycle,
                    'status' => Subscription::TRIALING, 'started_at' => $now, 'trial_ends_at' => $trialEnds,
                    'current_period_start' => $now, 'current_period_end' => $trialEnds,
                ]);
                $this->issueInvoice($sub, $plan, $cycle, $trialEnds, $this->addCycle($trialEnds, $cycle), $trialEnds);
            } else {
                $sub = Subscription::create([
                    'user_id' => $user->id, 'plan_id' => $plan->id, 'cycle' => $cycle,
                    'status' => Subscription::PENDING, 'started_at' => $now,
                ]);
                $this->issueInvoice($sub, $plan, $cycle, $now, $this->addCycle($now, $cycle), $now);
            }

            $this->log($sub, 'created', ['plan' => $plan->slug, 'cycle' => $cycle, 'trial_days' => $trial]);

            return $sub->fresh();
        });
    }

    /**
     * Upgrades are charged now (with credit for the unused time) and restart the period.
     * Downgrades and moving to Free are applied when the current period ends.
     */
    public function changePlan(Subscription $sub, Plan $plan, string $cycle = 'monthly', ?Carbon $now = null): ?Subscription
    {
        $now = $now ?? now();
        $cycle = $this->cycleFor($plan, $cycle);
        $current = $sub->plan;

        if ($plan->isFree()) {
            return $this->cancel($sub, $sub->status !== Subscription::PENDING, $now);
        }

        if ($plan->id === $current->id && $cycle === $sub->cycle) {
            $sub->update(['pending_plan_id' => null, 'pending_cycle' => null]);

            return $sub->fresh();
        }

        return DB::transaction(function () use ($sub, $plan, $cycle, $now, $current) {
            // Not paid yet (first payment still pending / trial): simply switch what will be charged
            if (in_array($sub->status, [Subscription::PENDING, Subscription::TRIALING, Subscription::PAST_DUE, Subscription::SUSPENDED], true)) {
                $open = $sub->openInvoice();
                [$start, $due] = $open ? [$open->period_start, $open->due_at] : [$sub->current_period_end ?? $now, $sub->current_period_end ?? $now];
                $this->voidOpenInvoices($sub);
                $sub->update(['plan_id' => $plan->id, 'cycle' => $cycle, 'pending_plan_id' => null, 'pending_cycle' => null]);
                $this->issueInvoice($sub, $plan, $cycle, $start, $this->addCycle($start, $cycle), $due);
                $this->log($sub, 'plan_changed', ['from' => $current->slug, 'to' => $plan->slug, 'immediate' => true]);

                return $sub->fresh();
            }

            if ($this->isUpgrade($current, $sub->cycle, $plan, $cycle)) {
                $credit = min($this->prorationCredit($sub, $now), $plan->priceFor($cycle));
                $this->voidOpenInvoices($sub);
                $this->issueInvoice(
                    $sub, $plan, $cycle, $now, $this->addCycle($now, $cycle), $now,
                    $credit, __('Upgrade to :plan', ['plan' => $plan->name])
                );
                $sub->update(['pending_plan_id' => null, 'pending_cycle' => null, 'cancel_at_period_end' => false, 'canceled_at' => null]);
                $this->log($sub, 'upgrade_requested', ['from' => $current->slug, 'to' => $plan->slug, 'credit_cents' => $credit]);

                return $sub->fresh();
            }

            $sub->update([
                'pending_plan_id' => $plan->id, 'pending_cycle' => $cycle,
                'cancel_at_period_end' => false, 'canceled_at' => null,
            ]);
            $this->log($sub, 'downgrade_scheduled', ['from' => $current->slug, 'to' => $plan->slug, 'at' => optional($sub->current_period_end)->toIso8601String()]);

            return $sub->fresh();
        });
    }

    public function cancel(Subscription $sub, bool $atPeriodEnd = true, ?Carbon $now = null): Subscription
    {
        $now = $now ?? now();

        $immediate = !$atPeriodEnd
            || !$sub->current_period_end
            || in_array($sub->status, [Subscription::PENDING, Subscription::PAST_DUE, Subscription::SUSPENDED], true);

        if ($immediate) {
            $this->voidOpenInvoices($sub);
            $sub->update([
                'status' => Subscription::CANCELED, 'canceled_at' => $now, 'ends_at' => $now,
                'cancel_at_period_end' => false, 'pending_plan_id' => null, 'pending_cycle' => null,
            ]);
            $this->log($sub, 'canceled', ['immediate' => true]);

            return $sub->fresh();
        }

        $this->voidOpenInvoices($sub); // no renewal wanted
        $sub->update(['cancel_at_period_end' => true, 'canceled_at' => $now, 'pending_plan_id' => null, 'pending_cycle' => null]);
        $this->log($sub, 'cancel_scheduled', ['at' => $sub->current_period_end->toIso8601String()]);

        return $sub->fresh();
    }

    public function resume(Subscription $sub): Subscription
    {
        if ($sub->cancel_at_period_end && in_array($sub->status, [Subscription::TRIALING, Subscription::ACTIVE], true)) {
            $sub->update(['cancel_at_period_end' => false, 'canceled_at' => null]);
            $this->log($sub, 'resumed');
        }

        return $sub->fresh();
    }

    /**
     * Called when a payment is confirmed (gateway webhook, or an admin for manual payments). Idempotent.
     */
    public function markInvoicePaid(Invoice $invoice, array $meta = [], ?Carbon $paidAt = null): Invoice
    {
        $paidAt = $paidAt ?? now();

        return DB::transaction(function () use ($invoice, $meta, $paidAt) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status === Invoice::PAID) {
                return $invoice;
            }
            if ($invoice->status === Invoice::VOID) {
                throw new \LogicException("Invoice {$invoice->number} is void and cannot be paid.");
            }

            $invoice->update([
                'status' => Invoice::PAID,
                'paid_at' => $paidAt,
                'payment_method' => $meta['payment_method'] ?? $invoice->payment_method,
                'gateway' => $meta['gateway'] ?? $invoice->gateway,
                'gateway_invoice_id' => $meta['gateway_invoice_id'] ?? $invoice->gateway_invoice_id,
                'gateway_payload' => $meta['payload'] ?? $invoice->gateway_payload,
            ]);

            $sub = Subscription::lockForUpdate()->findOrFail($invoice->subscription_id);

            // A long overdue account restarts its period today instead of paying for days it could not use
            $lapsed = $sub->status === Subscription::SUSPENDED || $invoice->period_end->lte($paidAt);
            $start = $lapsed ? $paidAt->copy() : $invoice->period_start->copy();
            $end = $lapsed ? $this->addCycle($paidAt, $invoice->cycle) : $invoice->period_end->copy();

            $sub->update([
                'plan_id' => $invoice->plan_id,
                'cycle' => $invoice->cycle,
                'status' => Subscription::ACTIVE,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'grace_ends_at' => null,
                'suspended_at' => null,
                'ends_at' => null,
                'pending_plan_id' => $sub->pending_plan_id === $invoice->plan_id ? null : $sub->pending_plan_id,
                'pending_cycle' => $sub->pending_plan_id === $invoice->plan_id ? null : $sub->pending_cycle,
            ]);

            // Other open invoices that overlap the period just paid (e.g. the old renewal after an upgrade)
            Invoice::where('subscription_id', $sub->id)->where('status', Invoice::OPEN)
                ->where('id', '!=', $invoice->id)->where('period_start', '<', $end)
                ->update(['status' => Invoice::VOID]);

            $this->log($sub, 'paid', ['invoice' => $invoice->number, 'amount_cents' => $invoice->amount_cents, 'period_end' => $end->toIso8601String()]);
            $this->notify($sub, 'paid', $invoice);

            return $invoice->fresh();
        });
    }

    /** Courtesy / migration: gives a plan for N days without charging (renewal invoices follow afterwards). */
    public function grant(User $user, Plan $plan, int $days, ?Carbon $now = null): ?Subscription
    {
        $now = $now ?? now();

        return DB::transaction(function () use ($user, $plan, $days, $now) {
            if ($live = $this->current($user)) {
                $this->cancel($live, false, $now);
            }

            if ($plan->isFree()) {
                return null;
            }

            $sub = Subscription::create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'cycle' => 'monthly',
                'status' => Subscription::ACTIVE, 'started_at' => $now,
                'current_period_start' => $now, 'current_period_end' => $now->copy()->addDays(max(1, $days)),
            ]);
            $this->log($sub, 'granted', ['plan' => $plan->slug, 'days' => $days]);

            return $sub;
        });
    }

    /**
     * Moves every subscription to the state its dates call for. Safe to run any number of times
     * (scheduled hourly: `php artisan billing:run`).
     *
     * @return array<string,int> counters per transition
     */
    public function runLifecycle(?Carbon $now = null): array
    {
        $now = $now ?? now();
        $stats = ['invoiced' => 0, 'past_due' => 0, 'suspended' => 0, 'expired' => 0, 'canceled' => 0];

        Subscription::whereIn('status', Subscription::liveStatuses())->orderBy('id')->each(function (Subscription $sub) use ($now, &$stats) {
            for ($i = 0; $i < 6; $i++) {
                $step = $this->advance($sub->fresh(), $now);
                if ($step === null) {
                    break;
                }
                $stats[$step] = ($stats[$step] ?? 0) + 1;
            }
        });

        return $stats;
    }

    /** One transition, or null when nothing is due. */
    private function advance(Subscription $sub, Carbon $now): ?string
    {
        switch ($sub->status) {
            case Subscription::PENDING:
                if ($sub->started_at && $sub->started_at->copy()->addDays((int) config('billing.pending_days', 3))->lte($now)) {
                    $this->voidOpenInvoices($sub);
                    $sub->update(['status' => Subscription::EXPIRED, 'ends_at' => $now]);
                    $this->log($sub, 'pending_expired');

                    return 'expired';
                }

                return null;

            case Subscription::TRIALING:
            case Subscription::ACTIVE:
                $end = $sub->current_period_end;
                if (!$end) {
                    return null;
                }

                if ($end->lte($now)) {
                    if ($sub->cancel_at_period_end) {
                        $sub->update(['status' => Subscription::CANCELED, 'ends_at' => $end, 'cancel_at_period_end' => false]);
                        $this->log($sub, 'canceled', ['immediate' => false]);

                        return 'canceled';
                    }

                    $invoice = $sub->openInvoice() ?? $this->issueRenewal($sub);
                    $graceEnds = $end->copy()->addDays((int) config('billing.grace_days', 3));
                    $sub->update(['status' => Subscription::PAST_DUE, 'grace_ends_at' => $graceEnds]);
                    $this->log($sub, 'past_due', ['grace_ends_at' => $graceEnds->toIso8601String()]);
                    $this->notify($sub, 'past_due', $invoice, ['grace_ends' => $graceEnds->format('d/m/Y')]);

                    return 'past_due';
                }

                if (!$sub->cancel_at_period_end
                    && $end->copy()->subDays((int) config('billing.invoice_days_before', 5))->lte($now)
                    && !$sub->openInvoice()) {
                    $this->issueRenewal($sub);

                    return 'invoiced';
                }

                return null;

            case Subscription::PAST_DUE:
                if ($sub->grace_ends_at && $sub->grace_ends_at->lte($now)) {
                    $sub->update(['status' => Subscription::SUSPENDED, 'suspended_at' => $sub->grace_ends_at]);
                    $this->log($sub, 'suspended');
                    $this->notify($sub, 'suspended', $sub->openInvoice());

                    return 'suspended';
                }

                return null;

            case Subscription::SUSPENDED:
                $until = optional($sub->suspended_at)->copy()->addDays((int) config('billing.suspension_days', 30));
                if ($until && $until->lte($now)) {
                    $this->voidOpenInvoices($sub);
                    $sub->update(['status' => Subscription::EXPIRED, 'ends_at' => $until]);
                    $this->log($sub, 'expired');
                    $this->notify($sub, 'expired');

                    return 'expired';
                }

                return null;
        }

        return null;
    }

    private function issueRenewal(Subscription $sub): Invoice
    {
        $plan = $sub->pending_plan_id ? Plan::find($sub->pending_plan_id) ?? $sub->plan : $sub->plan;
        $cycle = $this->cycleFor($plan, $sub->pending_cycle ?: $sub->cycle);
        $start = $sub->current_period_end->copy();

        $invoice = $this->issueInvoice($sub, $plan, $cycle, $start, $this->addCycle($start, $cycle), $start);
        $this->notify($sub, 'invoice_issued', $invoice);

        return $invoice;
    }

    private function issueInvoice(
        Subscription $sub, Plan $plan, string $cycle, Carbon $start, Carbon $end, Carbon $due,
        int $credit = 0, ?string $description = null
    ): Invoice {
        $price = $plan->priceFor($cycle);
        $invoice = Invoice::create([
            'user_id' => $sub->user_id,
            'subscription_id' => $sub->id,
            'plan_id' => $plan->id,
            'number' => Invoice::nextNumber(),
            'status' => Invoice::OPEN,
            'cycle' => $cycle,
            'amount_cents' => max(0, $price - $credit),
            'credit_cents' => $credit,
            'currency' => $plan->currency,
            'description' => $description ?? __(':plan plan (:cycle)', ['plan' => $plan->name, 'cycle' => __($cycle)]),
            'period_start' => $start,
            'period_end' => $end,
            'due_at' => $due,
        ]);

        $this->log($sub, 'invoice_issued', ['invoice' => $invoice->number, 'amount_cents' => $invoice->amount_cents]);

        // Fully covered by credit: nothing to collect
        if ($invoice->amount_cents === 0) {
            return $this->markInvoicePaid($invoice, ['payment_method' => 'credit']);
        }

        return $invoice;
    }

    private function voidOpenInvoices(Subscription $sub): void
    {
        Invoice::where('subscription_id', $sub->id)->where('status', Invoice::OPEN)->update(['status' => Invoice::VOID]);
    }

    private function prorationCredit(Subscription $sub, Carbon $now): int
    {
        $paid = Invoice::where('subscription_id', $sub->id)->where('status', Invoice::PAID)
            ->where('period_start', '<=', $now)->where('period_end', '>', $now)
            ->orderByDesc('period_end')->first();

        if (!$paid || $paid->amount_cents <= 0) {
            return 0;
        }

        $total = max(1, $paid->period_end->diffInSeconds($paid->period_start));
        $left = max(0, $paid->period_end->diffInSeconds($now));

        return (int) round($paid->amount_cents * min(1, $left / $total));
    }

    private function isUpgrade(Plan $from, string $fromCycle, Plan $to, string $toCycle): bool
    {
        if ($from->id === $to->id) {
            return $fromCycle === 'monthly' && $toCycle === 'yearly';
        }

        $equiv = fn (Plan $p, string $c) => $p->priceFor($c) / ($c === 'yearly' ? 12 : 1);

        return $equiv($to, $toCycle) > $equiv($from, $fromCycle);
    }

    private function trialDaysFor(User $user, Plan $plan): int
    {
        $days = (int) ($plan->trial_days ?? 0);
        if ($days <= 0) {
            return 0;
        }

        $had = Subscription::where('user_id', $user->id)->whereNotNull('trial_ends_at')->exists();

        return $had ? 0 : $days;
    }

    private function cycleFor(Plan $plan, string $cycle): string
    {
        return $cycle === 'yearly' && $plan->supportsYearly() ? 'yearly' : 'monthly';
    }

    private function addCycle(Carbon $from, string $cycle): Carbon
    {
        return $cycle === 'yearly' ? $from->copy()->addYearNoOverflow() : $from->copy()->addMonthNoOverflow();
    }

    private function log(Subscription $sub, string $type, array $data = []): void
    {
        SubscriptionEvent::create(['subscription_id' => $sub->id, 'user_id' => $sub->user_id, 'type' => $type, 'data' => $data]);
    }

    private function notify(Subscription $sub, string $type, ?Invoice $invoice = null, array $extra = []): void
    {
        try {
            $user = $sub->user ?? User::find($sub->user_id);
            if (!$user) {
                return;
            }

            $data = $extra;
            if ($invoice) {
                $data += [
                    'invoice' => $invoice->number,
                    'amount' => number_format($invoice->amount_cents / 100, 2, ',', '.') . ' ' . $invoice->currency,
                    'due' => optional($invoice->due_at)->format('d/m/Y'),
                ];
            }

            $user->notify(new BillingNotice($type, $data));
        } catch (\Throwable $e) {
            Log::warning('Billing notification failed: ' . $e->getMessage());
        }
    }
}
