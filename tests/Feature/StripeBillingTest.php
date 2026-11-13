<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Notifications\BillingNotice;
use App\Services\Billing\SubscriptionService;
use App\Services\Payments\GatewayManager;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Stripe Billing: automatic card renewal charged by Stripe, its webhook events and the customer portal (HTTP faked). */
class StripeBillingTest extends TestCase
{
    use CreatesTestSchema;

    private const CPF = '52998224725';

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Notification::fake();
        Cache::flush();
        config(['billing.enabled' => true, 'billing.currency' => 'BRL', 'app.url' => 'https://painel.test']);
        Carbon::setTestNow('2026-11-02 15:00:00');
        $this->service = app(SubscriptionService::class);
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $g = PaymentGateway::for('stripe');
        $g->forceFill(['enabled' => true, 'access_token' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
        $g->settings = PaymentGateway::DEFAULTS;
        $g->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeHttp(array $map): void
    {
        Http::swap(new Factory);
        Http::fake($map);
    }

    /** @return array{0:User,1:\App\Models\Subscription,2:Invoice} */
    private function checkout(): array
    {
        $user = User::create(['name' => 'Ana Silva', 'email' => 'ana@t.dev', 'username' => 'ana', 'password' => 'x']);
        $sub = $this->service->subscribe($user, Plan::where('slug', 'pro')->first(), 'monthly');

        return [$user->fresh(), $sub->fresh(), $sub->openInvoice()];
    }

    private function webhook(string $type, string $id)
    {
        $body = json_encode(['id' => 'evt_' . $id, 'type' => $type, 'data' => ['object' => ['id' => $id]]]);
        $t = now()->timestamp;

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], $this->transformHeadersToServerVars([
            'Stripe-Signature' => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", 'whsec_test'), 'Content-Type' => 'application/json',
        ]), $body);
    }

    /** Customer pays the first invoice by card with "renew automatically" and Stripe confirms the session. */
    private function subscribedCustomer(): array
    {
        [$user, $sub, $invoice] = $this->checkout();
        $this->fakeHttp([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_sub_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_sub_1']),
            'api.stripe.com/v1/checkout/sessions/cs_sub_1' => Http::response([
                'id' => 'cs_sub_1', 'mode' => 'subscription', 'payment_status' => 'paid', 'payment_method_types' => ['card'],
                'client_reference_id' => $invoice->number, 'amount_total' => $invoice->amount_cents, 'currency' => 'brl',
                'subscription' => 'sub_1', 'customer' => 'cus_1',
            ]),
        ]);

        $this->actingAs($user)->post(route('billing.pay', $invoice), ['method' => 'card', 'gateway' => 'stripe', 'document' => self::CPF, 'recurring' => 1])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_sub_1');
        $this->webhook('checkout.session.completed', 'cs_sub_1')->assertOk();

        return [$user, $sub->fresh(), $invoice->fresh()];
    }

    public function test_recurring_card_opens_a_subscription_mode_checkout_with_a_recurring_price(): void
    {
        [$user, $sub, $invoice] = $this->checkout();
        $this->fakeHttp(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_sub_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_sub_1'])]);

        $this->actingAs($user)->post(route('billing.pay', $invoice), ['method' => 'card', 'gateway' => 'stripe', 'document' => self::CPF, 'recurring' => 1])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_sub_1');

        Http::assertSent(fn ($q) => str_contains(urldecode($q->body()), 'mode=subscription')
            && str_contains(urldecode($q->body()), '[recurring][interval]=month')
            && str_contains(urldecode($q->body()), 'subscription_data[metadata][invoice]=' . $invoice->number));

        $sub = $sub->fresh();
        $this->assertTrue($sub->auto_renew);
        $this->assertSame('card', $sub->payment_method);
        $this->assertSame('stripe', $sub->gateway);
        $this->assertSame('cs_sub_1', $invoice->fresh()->gateway_invoice_id);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_sub_1', $invoice->fresh()->gateway_payload['redirect_url']);
    }

    public function test_pix_cannot_be_renewed_automatically_with_stripe(): void
    {
        [$user, , $invoice] = $this->checkout();
        $this->fakeHttp([]);

        $this->actingAs($user)->post(route('billing.pay', $invoice), ['method' => 'pix', 'gateway' => 'stripe', 'document' => self::CPF, 'recurring' => 1])
            ->assertSessionHasErrors('recurring');
        Http::assertNothingSent();
    }

    public function test_first_session_settles_the_invoice_and_stores_the_stripe_subscription(): void
    {
        [, $sub, $invoice] = $this->subscribedCustomer();

        $this->assertSame(Invoice::PAID, $invoice->status);
        $this->assertSame('sub_1', $sub->gateway_subscription_id);
        $this->assertSame('cus_1', $sub->gateway_customer_id);
        $this->assertSame('stripe', $sub->gateway);
        $this->assertTrue($sub->auto_renew);
    }

    public function test_renewal_charged_by_stripe_settles_the_next_invoice_once(): void
    {
        [, $sub, $first] = $this->subscribedCustomer();
        $periodEnd = $sub->current_period_end;

        $this->fakeHttp(['api.stripe.com/v1/invoices/in_2' => Http::response([
            'id' => 'in_2', 'status' => 'paid', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_1',
            'customer' => 'cus_1', 'amount_paid' => $first->amount_cents, 'currency' => 'brl',
        ])]);

        $this->webhook('invoice.paid', 'in_2')->assertOk();
        $this->webhook('invoice.paid', 'in_2')->assertOk();   // Stripe retries: must not charge the period twice

        $paid = Invoice::where('subscription_id', $sub->id)->where('status', Invoice::PAID)->get();
        $this->assertCount(2, $paid);
        $this->assertSame('in_2', $paid->firstWhere('id', '!=', $first->id)->gateway_invoice_id);
        $this->assertTrue($sub->fresh()->current_period_end->gt($periodEnd));
    }

    public function test_renewal_in_another_currency_or_lower_amount_is_not_applied(): void
    {
        [, $sub, $first] = $this->subscribedCustomer();

        $this->fakeHttp([
            'api.stripe.com/v1/invoices/in_usd' => Http::response(['id' => 'in_usd', 'status' => 'paid', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_1', 'amount_paid' => $first->amount_cents, 'currency' => 'usd']),
            'api.stripe.com/v1/invoices/in_low' => Http::response(['id' => 'in_low', 'status' => 'paid', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_1', 'amount_paid' => 100, 'currency' => 'brl']),
        ]);
        $this->webhook('invoice.paid', 'in_usd')->assertOk();
        $this->webhook('invoice.paid', 'in_low')->assertOk();

        $this->assertSame(1, Invoice::where('subscription_id', $sub->id)->where('status', Invoice::PAID)->count());
    }

    public function test_failed_renewal_charge_notifies_the_customer(): void
    {
        [$user] = $this->subscribedCustomer();
        $this->fakeHttp(['api.stripe.com/v1/invoices/in_3' => Http::response([
            'id' => 'in_3', 'status' => 'open', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_1', 'amount_paid' => 0, 'currency' => 'brl',
        ])]);

        $this->webhook('invoice.payment_failed', 'in_3')->assertOk();

        Notification::assertSentTo($user, BillingNotice::class, fn ($n) => ($this->noticeType($n)) === 'payment_failed');
    }

    public function test_subscription_deleted_at_stripe_turns_automatic_renewal_off(): void
    {
        [$user, $sub] = $this->subscribedCustomer();
        $this->fakeHttp(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'status' => 'canceled'])]);

        $this->webhook('customer.subscription.deleted', 'sub_1')->assertOk();

        $this->assertFalse($sub->fresh()->auto_renew);
        $this->assertSame('cancelled', $sub->fresh()->gateway_status);
        Notification::assertSentTo($user, BillingNotice::class, fn ($n) => $this->noticeType($n) === 'recurrence_canceled');
    }

    public function test_stopping_automatic_renewal_cancels_the_stripe_subscription(): void
    {
        [$user, $sub] = $this->subscribedCustomer();
        $this->fakeHttp(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'status' => 'canceled'])]);

        $this->actingAs($user)->post(route('billing.autorenew.stop'))->assertRedirect(route('billing.index'));

        Http::assertSent(fn ($q) => $q->method() === 'DELETE' && str_ends_with($q->url(), '/v1/subscriptions/sub_1'));
        $this->assertFalse($sub->fresh()->auto_renew);
    }

    public function test_plan_price_change_updates_the_recurring_price_without_proration(): void
    {
        [, $sub] = $this->subscribedCustomer();
        $this->fakeHttp(['api.stripe.com/v1/subscriptions/sub_1' => Http::response([
            'id' => 'sub_1', 'items' => ['data' => [['id' => 'si_1', 'price' => ['unit_amount' => 100, 'currency' => 'brl', 'product' => 'prod_1', 'recurring' => ['interval' => 'month']]]]],
        ])]);

        app(\App\Services\Payments\PaymentService::class)->syncRecurringAmount($sub->fresh(), 4990);

        Http::assertSent(fn ($q) => $q->method() === 'POST' && str_contains($q->body(), 'proration_behavior=none')
            && str_contains($q->body(), 'unit_amount%5D=4990') && str_contains($q->body(), 'product%5D=prod_1'));
    }

    public function test_customer_portal_redirects_to_stripe(): void
    {
        [$user] = $this->subscribedCustomer();
        $this->fakeHttp(['api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session/abc'])]);

        $this->actingAs($user)->get(route('billing.index'))->assertOk()->assertSee(route('billing.portal'), false);
        $this->actingAs($user)->get(route('billing.portal'))->assertRedirect('https://billing.stripe.com/p/session/abc');
        Http::assertSent(fn ($q) => str_contains($q->body(), 'customer=cus_1'));
    }

    public function test_portal_failure_goes_back_with_a_message(): void
    {
        [$user] = $this->subscribedCustomer();
        $this->fakeHttp(['api.stripe.com/v1/billing_portal/sessions' => Http::response(['error' => ['message' => 'no portal']], 400)]);

        $this->actingAs($user)->get(route('billing.portal'))->assertRedirect(route('billing.index'))->assertSessionHasErrors('portal');
    }

    public function test_refund_and_dispute_are_recorded_without_revoking_access(): void
    {
        [, $sub, $first] = $this->subscribedCustomer();
        $this->fakeHttp([
            'api.stripe.com/v1/charges/ch_1' => Http::response(['id' => 'ch_1', 'refunded' => true, 'amount_refunded' => $first->amount_cents, 'currency' => 'brl', 'invoice' => null, 'metadata' => ['invoice' => $first->number]]),
        ]);

        $this->webhook('charge.refunded', 'ch_1')->assertOk();

        $this->assertTrue(SubscriptionEvent::where('subscription_id', $sub->id)->where('type', 'refunded')->exists());
        $this->assertSame($first->amount_cents, $first->fresh()->gateway_payload['refunded_cents']);
        $this->assertSame(Invoice::PAID, $first->fresh()->status);
    }

    private function noticeType(BillingNotice $n): string
    {
        $r = new \ReflectionProperty($n, 'type');
        $r->setAccessible(true);

        return $r->getValue($n);
    }
}
