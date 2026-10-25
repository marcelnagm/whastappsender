<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\BillingNotice;
use App\Services\Billing\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class BillingLifecycleTest extends TestCase
{
    use CreatesTestSchema;

    private SubscriptionService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Notification::fake();
        config(['billing.enabled' => true, 'billing.trial_days' => 7, 'billing.grace_days' => 3,
            'billing.suspension_days' => 30, 'billing.invoice_days_before' => 5, 'billing.pending_days' => 3]);
        Carbon::setTestNow('2026-11-01 12:00:00');

        $this->service = app(SubscriptionService::class);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    private function run_(string $at): array
    {
        return $this->service->runLifecycle(Carbon::parse($at));
    }

    /** An active Pro subscription paid for 2026-11-01 .. 2026-12-01 (no trial). */
    private function activePro(): Subscription
    {
        config(['billing.plans.pro.trial_days' => 0]);
        Plan::where('slug', 'pro')->update(['trial_days' => 0]);
        $sub = $this->service->subscribe($this->user, $this->plan('pro'));
        $this->service->markInvoicePaid($sub->openInvoice());

        return $sub->fresh();
    }

    public function test_plans_are_seeded_by_the_migration(): void
    {
        $this->assertSame(['free', 'starter', 'pro', 'business'], Plan::orderBy('sort')->pluck('slug')->all());
        $this->assertTrue($this->plan('free')->isFree());
        $this->assertNull($this->plan('business')->limit('contacts'));
        $this->assertSame(0, $this->plan('free')->limit('unknown-limit'));
    }

    public function test_first_paid_subscription_starts_a_trial_with_the_first_invoice_due_when_it_ends(): void
    {
        $sub = $this->service->subscribe($this->user, $this->plan('pro'));

        $this->assertSame(Subscription::TRIALING, $sub->status);
        $this->assertTrue($sub->trial_ends_at->eq(Carbon::parse('2026-11-08 12:00:00')));
        $this->assertTrue($sub->current_period_end->eq($sub->trial_ends_at));

        $invoice = $sub->openInvoice();
        $this->assertSame(12900, $invoice->amount_cents);
        $this->assertTrue($invoice->due_at->eq($sub->trial_ends_at));
        $this->assertTrue($invoice->period_end->eq(Carbon::parse('2026-12-08 12:00:00')));
    }

    public function test_trial_is_only_given_once_per_user(): void
    {
        $first = $this->service->subscribe($this->user, $this->plan('starter'));
        $this->service->cancel($first, false);

        $second = $this->service->subscribe($this->user, $this->plan('pro'));

        $this->assertSame(Subscription::PENDING, $second->status);
        $this->assertTrue($second->openInvoice()->due_at->eq(now()));
    }

    public function test_paying_the_first_invoice_activates_and_sets_the_dates(): void
    {
        config(['billing.plans.pro.trial_days' => 0]);
        Plan::where('slug', 'pro')->update(['trial_days' => 0]);
        $sub = $this->service->subscribe($this->user, $this->plan('pro'));
        $this->assertSame(Subscription::PENDING, $sub->status);

        $paid = $this->service->markInvoicePaid($sub->openInvoice(), ['payment_method' => 'pix', 'gateway_invoice_id' => 'abc']);

        $sub->refresh();
        $this->assertSame(Invoice::PAID, $paid->status);
        $this->assertSame('pix', $paid->payment_method);
        $this->assertSame(Subscription::ACTIVE, $sub->status);
        $this->assertTrue($sub->current_period_start->eq(Carbon::parse('2026-11-01 12:00:00')));
        $this->assertTrue($sub->current_period_end->eq(Carbon::parse('2026-12-01 12:00:00')));
        Notification::assertSentTo($this->user, BillingNotice::class, fn ($n) => $n->type === 'paid');
    }

    public function test_marking_paid_twice_is_harmless_and_void_invoices_cannot_be_paid(): void
    {
        $sub = $this->activePro();
        $invoice = $sub->invoices()->first();
        $end = $sub->current_period_end->copy();

        $this->service->markInvoicePaid($invoice);
        $this->assertTrue($sub->fresh()->current_period_end->eq($end));

        $this->service->cancel($sub, false);
        $void = Invoice::create(['user_id' => $this->user->id, 'subscription_id' => $sub->id, 'plan_id' => 1, 'number' => 'X-1',
            'status' => Invoice::VOID, 'amount_cents' => 1, 'period_start' => now(), 'period_end' => now()->addMonth()]);
        $this->expectException(\LogicException::class);
        $this->service->markInvoicePaid($void);
    }

    public function test_renewal_invoice_is_issued_days_before_the_period_ends_only_once(): void
    {
        $sub = $this->activePro();

        $this->assertSame(0, $this->run_('2026-11-20 00:00:00')['invoiced']);
        $this->assertSame(1, $this->run_('2026-11-27 00:00:00')['invoiced']);
        $this->assertSame(0, $this->run_('2026-11-28 00:00:00')['invoiced']);

        $renewal = $sub->fresh()->openInvoice();
        $this->assertTrue($renewal->period_start->eq(Carbon::parse('2026-12-01 12:00:00')));
        $this->assertTrue($renewal->period_end->eq(Carbon::parse('2027-01-01 12:00:00')));
        $this->assertTrue($renewal->due_at->eq($renewal->period_start));

        // paying early extends from the old end, not from today
        $this->service->markInvoicePaid($renewal, [], Carbon::parse('2026-11-28 10:00:00'));
        $this->assertSame(Subscription::ACTIVE, $sub->fresh()->status);
        $this->assertTrue($sub->fresh()->current_period_end->eq(Carbon::parse('2027-01-01 12:00:00')));
    }

    public function test_unpaid_renewal_goes_past_due_then_suspended_then_expired_on_the_exact_dates(): void
    {
        $sub = $this->activePro();
        $this->run_('2026-11-28 00:00:00'); // renewal issued

        // period ended 2026-12-01 12:00 -> grace until 2026-12-04 12:00
        $this->assertSame(1, $this->run_('2026-12-01 13:00:00')['past_due']);
        $sub->refresh();
        $this->assertSame(Subscription::PAST_DUE, $sub->status);
        $this->assertTrue($sub->grace_ends_at->eq(Carbon::parse('2026-12-04 12:00:00')));

        $this->assertSame(0, $this->run_('2026-12-04 11:00:00')['suspended']);
        $this->assertSame(1, $this->run_('2026-12-04 13:00:00')['suspended']);
        $sub->refresh();
        $this->assertSame(Subscription::SUSPENDED, $sub->status);
        $this->assertTrue($sub->suspended_at->eq(Carbon::parse('2026-12-04 12:00:00')));

        $this->assertSame(0, $this->run_('2027-01-02 00:00:00')['expired']);
        $this->assertSame(1, $this->run_('2027-01-04 13:00:00')['expired']);
        $sub->refresh();
        $this->assertSame(Subscription::EXPIRED, $sub->status);
        $this->assertNull($sub->openInvoice(), 'open invoices are voided on expiry');
        Notification::assertSentTo($this->user, BillingNotice::class, fn ($n) => $n->type === 'suspended');
    }

    public function test_a_late_scheduler_run_catches_up_through_several_states(): void
    {
        $sub = $this->activePro();

        $stats = $this->run_('2027-03-01 00:00:00'); // scheduler was down for months

        $this->assertSame(Subscription::EXPIRED, $sub->fresh()->status);
        $this->assertSame(1, $stats['past_due']);
        $this->assertSame(1, $stats['suspended']);
        $this->assertSame(1, $stats['expired']);
    }

    public function test_paying_while_past_due_restores_access_and_keeps_the_paid_period(): void
    {
        $sub = $this->activePro();
        $this->run_('2026-11-28 00:00:00');
        $this->run_('2026-12-02 00:00:00');
        $this->assertSame(Subscription::PAST_DUE, $sub->fresh()->status);

        $this->service->markInvoicePaid($sub->fresh()->openInvoice(), [], Carbon::parse('2026-12-02 09:00:00'));

        $sub->refresh();
        $this->assertSame(Subscription::ACTIVE, $sub->status);
        $this->assertNull($sub->grace_ends_at);
        $this->assertTrue($sub->current_period_end->eq(Carbon::parse('2027-01-01 12:00:00')));
    }

    public function test_paying_after_suspension_restarts_the_period_from_the_payment_date(): void
    {
        $sub = $this->activePro();
        $this->run_('2026-11-28 00:00:00');
        $this->run_('2026-12-10 00:00:00');
        $this->assertSame(Subscription::SUSPENDED, $sub->fresh()->status);

        $this->service->markInvoicePaid($sub->fresh()->openInvoice(), [], Carbon::parse('2026-12-10 15:00:00'));

        $sub->refresh();
        $this->assertSame(Subscription::ACTIVE, $sub->status);
        $this->assertNull($sub->suspended_at);
        $this->assertTrue($sub->current_period_start->eq(Carbon::parse('2026-12-10 15:00:00')));
        $this->assertTrue($sub->current_period_end->eq(Carbon::parse('2027-01-10 15:00:00')));
    }

    public function test_unpaid_trial_becomes_past_due_when_it_ends(): void
    {
        $sub = $this->service->subscribe($this->user, $this->plan('pro'));

        $this->assertSame(0, $this->run_('2026-11-05 00:00:00')['past_due']);
        $this->assertSame(1, $this->run_('2026-11-08 13:00:00')['past_due']);
        $this->assertSame(Subscription::PAST_DUE, $sub->fresh()->status);
    }

    public function test_pending_request_expires_when_not_paid(): void
    {
        config(['billing.plans.pro.trial_days' => 0]);
        Plan::where('slug', 'pro')->update(['trial_days' => 0]);
        $sub = $this->service->subscribe($this->user, $this->plan('pro'));

        $this->assertSame(0, $this->run_('2026-11-03 00:00:00')['expired']);
        $this->assertSame(1, $this->run_('2026-11-04 13:00:00')['expired']);
        $this->assertSame(Subscription::EXPIRED, $sub->fresh()->status);
        $this->assertSame(Invoice::VOID, $sub->invoices()->first()->status);
    }

    public function test_cancel_at_period_end_keeps_access_then_ends_and_can_be_resumed(): void
    {
        $sub = $this->activePro();

        $this->service->cancel($sub);
        $sub->refresh();
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertSame(Subscription::ACTIVE, $sub->status);

        $this->run_('2026-11-28 00:00:00');
        $this->assertNull($sub->fresh()->openInvoice(), 'no renewal invoice for a cancelled subscription');

        $this->service->resume($sub);
        $this->assertFalse($sub->fresh()->cancel_at_period_end);
        $this->assertSame(1, $this->run_('2026-11-28 00:00:00')['invoiced']);

        $this->service->cancel($sub->fresh());
        $this->assertSame(1, $this->run_('2026-12-01 13:00:00')['canceled']);
        $sub->refresh();
        $this->assertSame(Subscription::CANCELED, $sub->status);
        $this->assertTrue($sub->ends_at->eq(Carbon::parse('2026-12-01 12:00:00')));
    }

    public function test_upgrade_is_charged_now_with_credit_for_the_unused_time(): void
    {
        $sub = $this->activePro();                       // 129,00 paid, 2026-11-01 12:00 -> 2026-12-01 12:00
        Carbon::setTestNow('2026-11-16 12:00:00');       // exactly half way (15 of 30 days)

        $this->service->changePlan($sub, $this->plan('business'));

        $invoice = $sub->fresh()->openInvoice();
        $this->assertSame(6450, $invoice->credit_cents);
        $this->assertSame(29900 - 6450, $invoice->amount_cents);
        $this->assertTrue($invoice->period_start->eq(now()));

        $this->service->markInvoicePaid($invoice);
        $sub->refresh();
        $this->assertSame('business', $sub->plan->slug);
        $this->assertTrue($sub->current_period_end->eq(Carbon::parse('2026-12-16 12:00:00')));
    }

    public function test_downgrade_is_scheduled_for_the_next_renewal(): void
    {
        $sub = $this->activePro();

        $this->service->changePlan($sub, $this->plan('starter'));
        $sub->refresh();
        $this->assertSame('pro', $sub->plan->slug);
        $this->assertSame('starter', $sub->pendingPlan->slug);
        $this->assertNull($sub->openInvoice());

        $this->run_('2026-11-28 00:00:00');
        $renewal = $sub->fresh()->openInvoice();
        $this->assertSame('starter', $renewal->plan->slug);
        $this->assertSame(4900, $renewal->amount_cents);

        $this->service->markInvoicePaid($renewal);
        $sub->refresh();
        $this->assertSame('starter', $sub->plan->slug);
        $this->assertNull($sub->pending_plan_id);
    }

    public function test_switching_to_free_cancels_at_period_end(): void
    {
        $sub = $this->activePro();

        $this->service->changePlan($sub, Plan::free());

        $this->assertTrue($sub->fresh()->cancel_at_period_end);
    }

    public function test_yearly_cycle_charges_ten_months_and_lasts_a_year(): void
    {
        config(['billing.plans.starter.trial_days' => 0]);
        Plan::where('slug', 'starter')->update(['trial_days' => 0]);

        $sub = $this->service->subscribe($this->user, $this->plan('starter'), 'yearly');
        $invoice = $sub->openInvoice();
        $this->assertSame(49000, $invoice->amount_cents);

        $this->service->markInvoicePaid($invoice);
        $this->assertTrue($sub->fresh()->current_period_end->eq(Carbon::parse('2027-11-01 12:00:00')));
    }

    public function test_grant_gives_access_without_invoice_and_renewal_follows(): void
    {
        $sub = $this->service->grant($this->user, $this->plan('pro'), 10);

        $this->assertSame(Subscription::ACTIVE, $sub->status);
        $this->assertSame(0, $sub->invoices()->count());
        $this->assertSame(1, $this->run_('2026-11-07 00:00:00')['invoiced']); // 5 days before day 10
    }

    public function test_lifecycle_events_are_logged_for_audit(): void
    {
        $sub = $this->activePro();
        $this->run_('2026-12-02 00:00:00');

        $types = $sub->events()->pluck('type')->all();
        foreach (['created', 'invoice_issued', 'paid', 'past_due'] as $expected) {
            $this->assertContains($expected, $types);
        }
    }
}
