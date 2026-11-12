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

/** PayPal: Orders (one-time), Subscriptions (renewal), signature verification and the webhook events (HTTP faked). */
class PayPalGatewayTest extends TestCase
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

        $g = PaymentGateway::for('paypal');
        $g->forceFill(['enabled' => true, 'public_key' => 'client-id', 'access_token' => 'client-secret', 'webhook_secret' => 'WH-1']);
        $g->settings = array_merge(PaymentGateway::DEFAULTS, ['sandbox' => true]);
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
        Http::fake(['api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3000])] + $map);
    }

    /** @return array{0:User,1:\App\Models\Subscription,2:Invoice} */
    private function checkout(): array
    {
        $user = User::create(['name' => 'Ana Silva', 'email' => 'ana@t.dev', 'username' => 'ana', 'password' => 'x']);
        $sub = $this->service->subscribe($user, Plan::where('slug', 'pro')->first(), 'monthly');

        return [$user->fresh(), $sub->fresh(), $sub->openInvoice()];
    }

    private function webhook(string $type, string $id, array $resource = [], array $headers = [])
    {
        $body = json_encode(['id' => 'WH-EVT-' . $id, 'event_type' => $type, 'resource' => ['id' => $id] + $resource]);

        return $this->call('POST', '/api/webhooks/paypal', [], [], [], $this->transformHeadersToServerVars($headers + [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert', 'PAYPAL-TRANSMISSION-ID' => 't1',
            'PAYPAL-TRANSMISSION-SIG' => 'sig', 'PAYPAL-TRANSMISSION-TIME' => '2026-11-02T15:00:00Z', 'Content-Type' => 'application/json',
        ]), $body);
    }

    private function verified(): array
    {
        return ['api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])];
    }

    /** First invoice paid by subscription (PayPal subscription I-1 exists from the start). */
    private function subscribedCustomer(): array
    {
        [$user, $sub, $invoice] = $this->checkout();
        $this->fakeHttp([
            'api-m.sandbox.paypal.com/v1/catalogs/products' => Http::response(['id' => 'PROD-1']),
            'api-m.sandbox.paypal.com/v1/billing/plans' => Http::response(['id' => 'P-1']),
            'api-m.sandbox.paypal.com/v1/billing/subscriptions' => Http::response(['id' => 'I-1', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-1']]]),
        ]);

        $this->actingAs($user)->post(route('billing.pay', $invoice), ['method' => 'card', 'gateway' => 'paypal', 'document' => self::CPF, 'recurring' => 1])
            ->assertRedirect('https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-1');

        return [$user, $sub->fresh(), $invoice->fresh()];
    }

    public function test_gateway_is_offered_with_card_only_and_needs_client_id_and_secret(): void
    {
        $gateway = app(GatewayManager::class)->driver('paypal');
        $this->assertTrue($gateway->isReady());
        $this->assertSame(['card'], $gateway->methods());
        $this->assertTrue($gateway->supportsCardRecurrence());
        $this->assertFalse($gateway->supportsPixRecurrence());
        $this->assertTrue($gateway->config()->isSandbox());

        PaymentGateway::for('paypal')->forceFill(['public_key' => null])->save();
        $this->assertFalse(app(GatewayManager::class)->driver('paypal')->isReady());
    }

    public function test_one_time_payment_creates_an_order_and_redirects_to_paypal(): void
    {
        [$user, , $invoice] = $this->checkout();
        $this->fakeHttp(['api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1']]])]);

        $this->actingAs($user)->post(route('billing.pay', $invoice), ['method' => 'card', 'gateway' => 'paypal', 'document' => self::CPF, 'recurring' => 0])
            ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/v2/checkout/orders') && $q->hasHeader('Authorization', 'Bearer tok')
            && $q['intent'] === 'CAPTURE' && $q['purchase_units'][0]['custom_id'] === $invoice->number
            && $q['purchase_units'][0]['amount']['currency_code'] === 'BRL'
            && $q['purchase_units'][0]['amount']['value'] === number_format($invoice->amount_cents / 100, 2, '.', ''));
        $this->assertSame('ORDER-1', $invoice->fresh()->gateway_invoice_id);
        $this->assertSame('paypal', $invoice->fresh()->gateway);
    }

    public function test_returning_from_paypal_captures_the_order_and_settles_the_invoice(): void
    {
        [$user, , $invoice] = $this->checkout();
        $invoice->update(['gateway' => 'paypal', 'gateway_invoice_id' => 'ORDER-1']);
        $this->fakeHttp([
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1/capture' => Http::response([
                'id' => 'ORDER-1', 'status' => 'COMPLETED',
                'purchase_units' => [['custom_id' => $invoice->number, 'payments' => ['captures' => [['id' => 'CAP-1', 'status' => 'COMPLETED', 'amount' => ['value' => number_format($invoice->amount_cents / 100, 2, '.', ''), 'currency_code' => 'BRL']]]]]],
            ]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
        ]);

        $this->actingAs($user)->get(route('billing.invoice', ['invoice' => $invoice, 'token' => 'ORDER-1']))->assertOk();

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame('CAP-1', $invoice->fresh()->gateway_invoice_id);
    }

    public function test_a_token_of_another_order_is_ignored_on_return(): void
    {
        [$user, , $invoice] = $this->checkout();
        $invoice->update(['gateway' => 'paypal', 'gateway_invoice_id' => 'ORDER-1']);
        $this->fakeHttp([]);

        $this->actingAs($user)->get(route('billing.invoice', ['invoice' => $invoice, 'token' => 'ORDER-OTHER']))->assertOk();

        Http::assertNotSent(fn ($q) => str_contains($q->url(), '/v2/checkout/orders'));
        $this->assertSame(Invoice::OPEN, $invoice->fresh()->status);
    }

    public function test_webhook_is_rejected_unless_paypal_verifies_the_signature(): void
    {
        $this->fakeHttp(['api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'])]);
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', 'CAP-1')->assertStatus(401);

        PaymentGateway::for('paypal')->forceFill(['webhook_secret' => null])->save();
        $this->fakeHttp($this->verified());
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', 'CAP-1')->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_capture_completed_webhook_settles_once_and_checks_the_amount(): void
    {
        [, , $invoice] = $this->checkout();
        $value = number_format($invoice->amount_cents / 100, 2, '.', '');
        $this->fakeHttp($this->verified() + [
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-LOW' => Http::response(['id' => 'CAP-LOW', 'status' => 'COMPLETED', 'custom_id' => $invoice->number, 'amount' => ['value' => '1.00', 'currency_code' => 'BRL']]),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-1' => Http::response(['id' => 'CAP-1', 'status' => 'COMPLETED', 'custom_id' => $invoice->number, 'amount' => ['value' => $value, 'currency_code' => 'BRL']]),
        ]);

        $this->webhook('PAYMENT.CAPTURE.COMPLETED', 'CAP-LOW')->assertOk();
        $this->assertSame(Invoice::OPEN, $invoice->fresh()->status);

        $this->webhook('PAYMENT.CAPTURE.COMPLETED', 'CAP-1')->assertOk();
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', 'CAP-1')->assertOk();
        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame(1, Invoice::where('status', Invoice::PAID)->count());
    }

    public function test_recurring_card_creates_a_plan_and_a_subscription_for_the_customer(): void
    {
        [, $sub, $invoice] = $this->subscribedCustomer();

        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/v1/billing/plans') && $q['product_id'] === 'PROD-1'
            && $q['billing_cycles'][0]['frequency']['interval_unit'] === 'MONTH'
            && $q['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] === number_format($invoice->amount_cents / 100, 2, '.', ''));
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/v1/billing/subscriptions') && $q['plan_id'] === 'P-1' && $q['custom_id'] === $invoice->number);

        $this->assertSame('I-1', $sub->gateway_subscription_id);
        $this->assertSame('paypal', $sub->gateway);
        $this->assertTrue($sub->auto_renew);
        $this->assertSame('card', $sub->payment_method);
    }

    public function test_sale_completed_settles_first_invoice_then_each_renewal_once(): void
    {
        [, $sub, $first] = $this->subscribedCustomer();
        $value = number_format($first->amount_cents / 100, 2, '.', '');
        $this->fakeHttp($this->verified() + [
            'api-m.sandbox.paypal.com/v1/payments/sale/SALE-1' => Http::response(['id' => 'SALE-1', 'state' => 'completed', 'billing_agreement_id' => 'I-1', 'amount' => ['total' => $value, 'currency' => 'BRL']]),
            'api-m.sandbox.paypal.com/v1/payments/sale/SALE-2' => Http::response(['id' => 'SALE-2', 'state' => 'completed', 'billing_agreement_id' => 'I-1', 'amount' => ['total' => $value, 'currency' => 'BRL']]),
        ]);

        $this->webhook('PAYMENT.SALE.COMPLETED', 'SALE-1')->assertOk();
        $this->assertSame(Invoice::PAID, $first->fresh()->status);
        $end = $sub->fresh()->current_period_end;

        $this->webhook('PAYMENT.SALE.COMPLETED', 'SALE-1')->assertOk();   // replay
        $this->assertSame(1, Invoice::where('subscription_id', $sub->id)->where('status', Invoice::PAID)->count());

        $this->webhook('PAYMENT.SALE.COMPLETED', 'SALE-2')->assertOk();   // next cycle
        $this->assertSame(2, Invoice::where('subscription_id', $sub->id)->where('status', Invoice::PAID)->count());
        $this->assertTrue($sub->fresh()->current_period_end->gt($end));
    }

    public function test_payment_failed_and_cancelled_subscription_notify_and_stop_renewal(): void
    {
        [$user, $sub] = $this->subscribedCustomer();
        $this->fakeHttp($this->verified() + ['api-m.sandbox.paypal.com/v1/billing/subscriptions/I-1' => Http::response(['id' => 'I-1', 'status' => 'CANCELLED'])]);

        $this->webhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'I-1')->assertOk();
        Notification::assertSentTo($user, BillingNotice::class, fn ($n) => $this->type($n) === 'payment_failed');

        $this->webhook('BILLING.SUBSCRIPTION.CANCELLED', 'I-1')->assertOk();
        $this->assertFalse($sub->fresh()->auto_renew);
        Notification::assertSentTo($user, BillingNotice::class, fn ($n) => $this->type($n) === 'recurrence_canceled');
    }

    public function test_a_subscription_event_that_does_not_match_the_status_is_ignored(): void
    {
        [, $sub] = $this->subscribedCustomer();
        $this->fakeHttp($this->verified() + ['api-m.sandbox.paypal.com/v1/billing/subscriptions/I-1' => Http::response(['id' => 'I-1', 'status' => 'ACTIVE'])]);

        $this->webhook('BILLING.SUBSCRIPTION.CANCELLED', 'I-1')->assertOk();

        $this->assertTrue($sub->fresh()->auto_renew);
    }

    public function test_stopping_automatic_renewal_cancels_the_paypal_subscription(): void
    {
        [$user, $sub] = $this->subscribedCustomer();
        $this->fakeHttp(['api-m.sandbox.paypal.com/v1/billing/subscriptions/I-1/cancel' => Http::response('', 204)]);

        $this->actingAs($user)->post(route('billing.autorenew.stop'))->assertRedirect(route('billing.index'));

        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/v1/billing/subscriptions/I-1/cancel'));
        $this->assertFalse($sub->fresh()->auto_renew);
    }

    public function test_price_change_updates_the_plan_pricing_scheme(): void
    {
        [, $sub] = $this->subscribedCustomer();
        $this->fakeHttp([
            'api-m.sandbox.paypal.com/v1/billing/subscriptions/I-1' => Http::response(['id' => 'I-1', 'plan_id' => 'P-1']),
            'api-m.sandbox.paypal.com/v1/billing/plans/P-1/update-pricing-schemes' => Http::response('', 204),
            'api-m.sandbox.paypal.com/v1/billing/plans/P-1' => Http::response(['id' => 'P-1', 'billing_cycles' => [['pricing_scheme' => ['fixed_price' => ['value' => '1.00', 'currency_code' => 'BRL']]]]]),
        ]);

        app(\App\Services\Payments\PaymentService::class)->syncRecurringAmount($sub->fresh(), 4990);

        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/update-pricing-schemes') && $q['pricing_schemes'][0]['pricing_scheme']['fixed_price']['value'] === '49.90');
    }

    public function test_refund_is_recorded_on_the_invoice_without_revoking_access(): void
    {
        [, $sub, $first] = $this->subscribedCustomer();
        $value = number_format($first->amount_cents / 100, 2, '.', '');
        $this->fakeHttp($this->verified() + [
            'api-m.sandbox.paypal.com/v1/payments/sale/SALE-1' => Http::response(['id' => 'SALE-1', 'state' => 'completed', 'billing_agreement_id' => 'I-1', 'amount' => ['total' => $value, 'currency' => 'BRL']]),
            'api-m.sandbox.paypal.com/v1/payments/refund/REF-1' => Http::response(['id' => 'REF-1', 'sale_id' => 'SALE-1', 'amount' => ['total' => $value, 'currency' => 'BRL']]),
        ]);
        $this->webhook('PAYMENT.SALE.COMPLETED', 'SALE-1')->assertOk();

        $this->webhook('PAYMENT.SALE.REFUNDED', 'REF-1')->assertOk();

        $this->assertTrue(SubscriptionEvent::where('subscription_id', $sub->id)->where('type', 'refunded')->exists());
        $this->assertSame(Invoice::PAID, $first->fresh()->status);
    }

    public function test_test_connection_reports_the_environment(): void
    {
        $this->fakeHttp([]);
        $r = app(GatewayManager::class)->driver('paypal')->testConnection();
        $this->assertTrue($r['ok']);
        $this->assertSame('PayPal (sandbox)', $r['account']);

        Http::swap(new Factory);
        Http::fake(['api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401)]);
        Cache::flush();
        $this->assertFalse(app(GatewayManager::class)->driver('paypal')->testConnection()['ok']);
    }

    public function test_admin_tab_shows_client_id_webhook_id_and_no_pix(): void
    {
        $admin = User::create(['name' => 'Boss', 'email' => 'boss@t.dev', 'username' => 'boss', 'password' => 'x']);
        $admin->forceFill(['role' => 'admin'])->save();

        $this->actingAs($admin->fresh())->get('/admin/payment-gateway?provider=paypal')->assertOk()
            ->assertSee('Client ID')->assertSee('Webhook ID')->assertDontSee('id="pix_enabled"', false);
    }

    private function type(BillingNotice $n): string
    {
        $r = new \ReflectionProperty($n, 'type');
        $r->setAccessible(true);

        return $r->getValue($n);
    }
}
