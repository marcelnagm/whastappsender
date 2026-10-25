<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\BillingNotice;
use App\Services\Billing\SubscriptionService;
use App\Services\Payments\PaymentService;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    use CreatesTestSchema;

    private const CPF = '52998224725'; // valid test CPF

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Notification::fake();
        Cache::flush();
        config(['billing.enabled' => true, 'app.url' => 'https://painel.test']);
        Carbon::setTestNow('2026-11-02 15:00:00');
        $this->service = app(SubscriptionService::class);
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gateway(array $over = [], array $settings = []): PaymentGateway
    {
        $g = PaymentGateway::mercadopago();
        $g->forceFill(array_merge(['enabled' => true, 'access_token' => 'TEST-token-123456', 'public_key' => 'TEST-pub-1', 'webhook_secret' => null], $over));
        $g->settings = array_merge(PaymentGateway::DEFAULTS, $settings);
        $g->save();

        return $g;
    }

    private function user(string $name = 'ana', ?string $role = null): User
    {
        $u = User::create(['name' => 'Ana Silva', 'email' => "$name@t.dev", 'username' => $name, 'password' => 'x']);
        if ($role) {
            $u->forceFill(['role' => $role])->save();
        }

        return $u->fresh();
    }

    /** @return array{0:User,1:Subscription,2:Invoice} */
    private function checkout(string $plan = 'pro', string $cycle = 'monthly', string $name = 'ana'): array
    {
        $user = $this->user($name);
        $sub = $this->service->subscribe($user, Plan::where('slug', $plan)->first(), $cycle);
        $invoice = $sub->openInvoice();
        $this->assertNotNull($invoice);

        return [$user, $sub->fresh(), $invoice];
    }

    private function fakeHttp(array $map): void
    {
        Http::swap(new Factory);
        Http::fake($map);
    }

    private function pixResponse(string $id = '9001', float $amount = 129.0): array
    {
        return ['id' => (int) $id, 'status' => 'pending', 'status_detail' => 'pending_waiting_transfer', 'payment_type_id' => 'bank_transfer',
            'transaction_amount' => $amount, 'date_of_expiration' => now()->addDay()->toIso8601String(),
            'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020126PIX', 'qr_code_base64' => 'QUJD', 'ticket_url' => 'https://mp/ticket']]];
    }

    private function approved(string $id, string $reference, float $amount, string $type = 'credit_card'): array
    {
        return ['id' => (int) $id, 'status' => 'approved', 'payment_type_id' => $type, 'transaction_amount' => $amount, 'external_reference' => $reference, 'payment_method_id' => 'visa'];
    }

    // ---- Admin settings --------------------------------------------------------------------------------

    public function test_only_admins_reach_the_gateway_settings(): void
    {
        $this->actingAs($this->user('plain'))->get('/admin/payment-gateway')->assertRedirect();
        $this->actingAs($this->user('boss', 'admin'))->get('/admin/payment-gateway')->assertOk()->assertSee('Mercado Pago');
    }

    public function test_settings_are_saved_encrypted_masked_and_blank_keeps_the_secret(): void
    {
        $admin = $this->user('boss', 'admin');
        $payload = ['enabled' => 1, 'access_token' => 'APP_USR-secret-token-9999', 'public_key' => 'APP_USR-pub', 'webhook_secret' => 'whsec-abc',
            'card_enabled' => 1, 'pix_enabled' => 1, 'recurrence_enabled' => 1, 'recurrence_default' => 0, 'pix_expiration_minutes' => 60, 'statement_descriptor' => 'SAMA'];

        $this->actingAs($admin)->post('/admin/payment-gateway', $payload)->assertRedirect(route('admin.gateway.edit'));

        $raw = \DB::table('payment_gateways')->where('provider', 'mercadopago')->first();
        $this->assertStringNotContainsString('APP_USR-secret-token-9999', $raw->access_token);
        $this->assertStringNotContainsString('whsec-abc', $raw->webhook_secret);

        $g = PaymentGateway::mercadopago()->fresh();
        $this->assertSame('APP_USR-secret-token-9999', $g->access_token);
        $this->assertTrue($g->isReady());
        $this->assertFalse($g->isSandbox());
        $this->assertSame(60, $g->setting('pix_expiration_minutes'));
        $this->assertFalse($g->setting('recurrence_default'));

        $page = $this->actingAs($admin)->get('/admin/payment-gateway')->assertOk();
        $page->assertDontSee('APP_USR-secret-token-9999');
        $page->assertSee('APP_US…9999');

        // blank secrets keep the stored ones
        $this->actingAs($admin)->post('/admin/payment-gateway', array_merge($payload, ['access_token' => '', 'webhook_secret' => '', 'statement_descriptor' => 'NEW']))->assertSessionHasNoErrors();
        $g = PaymentGateway::mercadopago()->fresh();
        $this->assertSame('APP_USR-secret-token-9999', $g->access_token);
        $this->assertSame('whsec-abc', $g->webhook_secret);
        $this->assertSame('NEW', $g->setting('statement_descriptor'));
    }

    public function test_card_is_not_offered_without_public_key_and_nothing_without_a_token(): void
    {
        $g = $this->gateway(['public_key' => null]);
        $this->assertSame(['pix'], $g->methods());

        $g = $this->gateway(['access_token' => null]);
        $this->assertFalse($g->isReady());
        $this->assertNull(app(PaymentService::class)->gateway());
    }

    public function test_connection_test_reports_the_account(): void
    {
        $this->gateway();
        $this->fakeHttp(['api.mercadopago.com/users/me' => Http::response(['id' => 77, 'nickname' => 'SAMA', 'email' => 'a@b.c', 'site_id' => 'MLB'])]);

        $this->actingAs($this->user('boss', 'admin'))->post('/admin/payment-gateway/test')
            ->assertRedirect()->assertSessionHas('success');

        $this->fakeHttp(['api.mercadopago.com/users/me' => Http::response(['message' => 'invalid'], 401)]);
        $this->actingAs($this->user('boss2', 'admin'))->post('/admin/payment-gateway/test')->assertSessionHas('error');
    }

    // ---- Pix ----------------------------------------------------------------------------------------------

    public function test_pix_charge_sends_the_invoice_and_stores_the_qr_code(): void
    {
        $this->gateway();
        [$user, $sub, $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments' => Http::response($this->pixResponse('9001', $invoice->amount_cents / 100), 201)]);

        $this->actingAs($user)->postJson("/billing/invoices/{$invoice->id}/pay", ['method' => 'pix', 'document' => '529.982.247-25', 'recurring' => 0])
            ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('pix.qr_code', '00020126PIX');

        Http::assertSent(function (HttpRequest $r) use ($invoice) {
            $b = $r->data();

            return $r->method() === 'POST' && str_ends_with($r->url(), '/v1/payments')
                && $r->hasHeader('Authorization', 'Bearer TEST-token-123456') && $r->hasHeader('X-Idempotency-Key')
                && $b['payment_method_id'] === 'pix' && $b['external_reference'] === $invoice->number
                && abs($b['transaction_amount'] - $invoice->amount_cents / 100) < 0.001
                && $b['payer']['identification'] === ['type' => 'CPF', 'number' => '52998224725']
                && $b['notification_url'] === 'https://painel.test/api/webhooks/mercadopago';
        });

        $invoice->refresh();
        $this->assertSame('9001', $invoice->gateway_invoice_id);
        $this->assertSame('00020126PIX', $invoice->gateway_payload['pix']['qr_code']);
        $this->assertTrue($invoice->isOpen());
        $this->assertFalse($sub->fresh()->auto_renew, 'one-time Pix does not start recurrence');
    }

    public function test_a_valid_pix_is_reused_instead_of_creating_another_charge(): void
    {
        $this->gateway();
        [$user, , $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments' => Http::response($this->pixResponse('9001', $invoice->amount_cents / 100), 201)]);

        $url = "/billing/invoices/{$invoice->id}/pay";
        $this->actingAs($user)->postJson($url, ['method' => 'pix', 'document' => self::CPF]);
        $this->actingAs($user)->postJson($url, ['method' => 'pix', 'document' => self::CPF])->assertOk()->assertJsonPath('pix.qr_code', '00020126PIX');

        Http::assertSentCount(1);
    }

    public function test_pix_with_recurrence_marks_the_subscription_for_automatic_pix(): void
    {
        $this->gateway();
        [$user, $sub, $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments' => Http::response($this->pixResponse('9001', $invoice->amount_cents / 100), 201)]);

        $this->actingAs($user)->postJson("/billing/invoices/{$invoice->id}/pay", ['method' => 'pix', 'document' => self::CPF, 'recurring' => 1])->assertOk();

        $sub->refresh();
        $this->assertTrue($sub->auto_renew);
        $this->assertSame('pix', $sub->payment_method);
        $this->assertSame(self::CPF, $sub->payer_document);
        $this->assertArrayNotHasKey('payer_document', $sub->toArray(), 'document stays hidden');
    }

    public function test_renewal_invoice_of_a_pix_subscriber_gets_an_automatic_pix_and_an_email(): void
    {
        $this->gateway();
        [$user, $sub, $invoice] = $this->checkout();
        $this->service->markInvoicePaid($invoice, ['gateway' => 'mercadopago', 'payment_method' => 'pix']);
        $sub->refresh()->update(['auto_renew' => true, 'payment_method' => 'pix', 'payer_document' => self::CPF]);

        $this->fakeHttp(['api.mercadopago.com/v1/payments' => Http::response($this->pixResponse('9100', 129.0), 201)]);

        Carbon::setTestNow($sub->current_period_end->copy()->subDays(4)); // inside the invoice window (5 days)
        $this->service->runLifecycle();

        $renewal = Invoice::where('subscription_id', $sub->id)->where('status', 'open')->first();
        $this->assertNotNull($renewal, 'renewal invoice issued');
        $this->assertSame('9100', $renewal->gateway_invoice_id);
        $this->assertSame('00020126PIX', $renewal->gateway_payload['pix']['qr_code']);
        Notification::assertSentTo($user, BillingNotice::class, fn ($n) => $n->type === 'pix_ready' && $n->data['pix_code'] === '00020126PIX');
        Http::assertSentCount(1);
    }

    // ---- Card -----------------------------------------------------------------------------------------------

    public function test_one_time_card_payment_settles_the_invoice(): void
    {
        $this->gateway();
        [$user, $sub, $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments' => Http::response($this->approved('555', $invoice->number, $invoice->amount_cents / 100), 201)]);

        $this->actingAs($user)->postJson("/billing/invoices/{$invoice->id}/pay", [
            'method' => 'card', 'document' => self::CPF, 'card_token' => 'tok_1', 'payment_method_id' => 'visa', 'issuer_id' => '24', 'recurring' => 0,
        ])->assertOk()->assertJsonPath('paid', true);

        Http::assertSent(fn (HttpRequest $r) => ($r->data()['token'] ?? null) === 'tok_1' && $r->data()['installments'] === 1 && $r->data()['issuer_id'] === 24);

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame('card', $invoice->fresh()->payment_method);
        $this->assertSame(Subscription::ACTIVE, $sub->fresh()->status);
        $this->assertFalse($sub->fresh()->auto_renew);
    }

    public function test_rejected_card_keeps_the_invoice_open_with_a_friendly_message(): void
    {
        $this->gateway();
        [$user, , $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments' => Http::response(['id' => 1, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount'], 201)]);

        $this->actingAs($user)->postJson("/billing/invoices/{$invoice->id}/pay", ['method' => 'card', 'document' => self::CPF, 'card_token' => 't'])
            ->assertStatus(422)->assertJsonPath('status', 'rejected');

        $this->assertTrue($invoice->fresh()->isOpen());
    }

    public function test_recurring_card_creates_a_preapproval_and_waits_for_the_first_charge(): void
    {
        $this->gateway();
        [$user, $sub, $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/preapproval' => Http::response(['id' => 'PRE-1', 'status' => 'authorized'], 201)]);

        $this->actingAs($user)->postJson("/billing/invoices/{$invoice->id}/pay", [
            'method' => 'card', 'document' => self::CPF, 'card_token' => 'tok_2', 'payment_method_id' => 'master', 'recurring' => 1,
        ])->assertOk()->assertJsonPath('status', 'pending');

        Http::assertSent(function (HttpRequest $r) use ($sub) {
            $b = $r->data();

            return str_ends_with($r->url(), '/preapproval') && $b['card_token_id'] === 'tok_2' && $b['status'] === 'authorized'
                && $b['external_reference'] === 'sub-' . $sub->id && $b['auto_recurring']['frequency'] === 1
                && $b['auto_recurring']['frequency_type'] === 'months' && $b['auto_recurring']['transaction_amount'] == 129.0;
        });

        $sub->refresh();
        $this->assertSame('PRE-1', $sub->gateway_subscription_id);
        $this->assertTrue($sub->auto_renew);
        $this->assertSame('card', $sub->payment_method);
        $this->assertTrue($invoice->fresh()->isOpen(), 'access only after the charge is confirmed');
    }

    public function test_recurring_card_on_a_prorated_invoice_is_refused(): void
    {
        $this->gateway();
        [$user, , $invoice] = $this->checkout();
        $invoice->update(['amount_cents' => 5000]); // e.g. upgrade with credit
        $this->fakeHttp(['*' => Http::response([], 500)]);

        $this->actingAs($user)->postJson("/billing/invoices/{$invoice->id}/pay", ['method' => 'card', 'document' => self::CPF, 'card_token' => 't', 'recurring' => 1])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['recurring']]);

        Http::assertNothingSent();
    }

    // ---- Checkout rules ----------------------------------------------------------------------------------------

    public function test_pay_validates_document_method_and_ownership(): void
    {
        $this->gateway([], ['pix_enabled' => false]);
        [$user, , $invoice] = $this->checkout();
        $this->fakeHttp(['*' => Http::response([], 500)]);
        $url = "/billing/invoices/{$invoice->id}/pay";

        $this->actingAs($user)->postJson($url, ['method' => 'card', 'document' => '111.111.111-11', 'card_token' => 't'])->assertStatus(422)->assertJsonStructure(['errors' => ['document']]);
        $this->actingAs($user)->postJson($url, ['method' => 'pix', 'document' => self::CPF])->assertStatus(422)->assertJsonStructure(['errors' => ['method']]);
        $this->actingAs($user)->postJson($url, ['method' => 'card', 'document' => self::CPF])->assertStatus(422)->assertJsonStructure(['errors' => ['card']]);
        $this->actingAs($this->user('other'))->postJson($url, ['method' => 'card', 'document' => self::CPF, 'card_token' => 't'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_checkout_page_shows_methods_and_polling_endpoint_is_private(): void
    {
        $this->gateway();
        [$user, , $invoice] = $this->checkout();

        $this->actingAs($user)->get("/billing/invoices/{$invoice->id}")->assertOk()
            ->assertSee('sdk.mercadopago.com/js/v2', false)->assertSee('name="document"', false)->assertSee('Pix');

        $this->actingAs($user)->getJson("/billing/invoices/{$invoice->id}/status")->assertOk()->assertJson(['status' => 'open', 'paid' => false]);
        $this->actingAs($this->user('other'))->getJson("/billing/invoices/{$invoice->id}/status")->assertForbidden();

        PaymentGateway::mercadopago()->update(['enabled' => false]);
        $this->actingAs($user)->get("/billing/invoices/{$invoice->id}")->assertOk()->assertSee('Online payment is not available');
    }

    public function test_stopping_automatic_renewal_cancels_the_card_authorization(): void
    {
        $this->gateway();
        [$user, $sub] = $this->checkout();
        $sub->update(['auto_renew' => true, 'payment_method' => 'card', 'gateway_subscription_id' => 'PRE-9', 'gateway_status' => 'authorized']);
        $this->fakeHttp(['api.mercadopago.com/preapproval/PRE-9' => Http::response(['status' => 'cancelled'])]);

        $this->actingAs($user)->post('/billing/auto-renew/stop')->assertRedirect(route('billing.index'));

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/preapproval/PRE-9') && $r->data() === ['status' => 'cancelled']);
        $this->assertFalse($sub->fresh()->auto_renew);
        $this->assertSame('cancelled', $sub->fresh()->gateway_status);
    }

    public function test_canceling_the_subscription_stops_the_recurring_charges(): void
    {
        $this->gateway();
        [, $sub, $invoice] = $this->checkout();
        $this->service->markInvoicePaid($invoice, []);
        $sub->refresh()->update(['auto_renew' => true, 'payment_method' => 'card', 'gateway_subscription_id' => 'PRE-7']);
        $this->fakeHttp(['api.mercadopago.com/preapproval/PRE-7' => Http::response([])]);

        $this->service->cancel($sub->fresh(), false);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/preapproval/PRE-7'));
        $this->assertFalse($sub->fresh()->auto_renew);
    }

    // ---- Webhook ----------------------------------------------------------------------------------------------------

    private function notify(string $type, string $id, array $headers = [])
    {
        return $this->withHeaders($headers)->postJson("/api/webhooks/mercadopago?data.id={$id}&type={$type}", ['type' => $type, 'data' => ['id' => $id]]);
    }

    public function test_approved_payment_notification_settles_the_invoice(): void
    {
        $this->gateway();
        [, $sub, $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments/9001' => Http::response($this->approved('9001', $invoice->number, $invoice->amount_cents / 100, 'bank_transfer'))]);

        $this->notify('payment', '9001')->assertOk()->assertJson(['status' => 'queued']);

        $invoice->refresh();
        $this->assertSame(Invoice::PAID, $invoice->status);
        $this->assertSame('pix', $invoice->payment_method);
        $this->assertSame('9001', $invoice->gateway_invoice_id);
        $this->assertSame(Subscription::ACTIVE, $sub->fresh()->status);
        $this->assertSame('processed', WebhookEvent::first()->status);
    }

    public function test_replayed_notification_does_not_pay_twice(): void
    {
        $this->gateway();
        [, $sub, $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments/9001' => Http::response($this->approved('9001', $invoice->number, $invoice->amount_cents / 100))]);

        $this->notify('payment', '9001');
        $end = $sub->fresh()->current_period_end;
        $this->notify('payment', '9001');

        $this->assertEquals($end, $sub->fresh()->current_period_end, 'period is not extended twice');
        $this->assertSame(1, Invoice::where('status', 'paid')->count());
    }

    public function test_payment_with_a_lower_amount_or_unknown_reference_is_ignored(): void
    {
        $this->gateway();
        [, , $invoice] = $this->checkout();
        $this->fakeHttp([
            'api.mercadopago.com/v1/payments/1' => Http::response($this->approved('1', $invoice->number, 1.00)),
            'api.mercadopago.com/v1/payments/2' => Http::response($this->approved('2', 'INV-NOPE', 500)),
            'api.mercadopago.com/v1/payments/3' => Http::response($this->approved('3', 'something-else', 500)),
        ]);

        foreach (['1', '2', '3'] as $id) {
            $this->notify('payment', $id)->assertOk();
        }

        $this->assertTrue($invoice->fresh()->isOpen());
        $this->assertSame(3, WebhookEvent::where('status', 'ignored')->count());
    }

    public function test_pending_payment_notification_leaves_the_invoice_open(): void
    {
        $this->gateway();
        [, , $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments/5' => Http::response(['id' => 5, 'status' => 'pending', 'external_reference' => $invoice->number, 'transaction_amount' => 129])]);

        $this->notify('payment', '5');

        $this->assertTrue($invoice->fresh()->isOpen());
    }

    public function test_signature_is_enforced_when_a_secret_is_configured(): void
    {
        $this->gateway(['webhook_secret' => 'whsec']);
        [, , $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments/9001' => Http::response($this->approved('9001', $invoice->number, $invoice->amount_cents / 100))]);

        $this->notify('payment', '9001')->assertStatus(401);
        $this->notify('payment', '9001', ['x-signature' => 'ts=1,v1=deadbeef', 'x-request-id' => 'r1'])->assertStatus(401);
        $this->assertTrue($invoice->fresh()->isOpen());
        $this->assertSame(2, WebhookEvent::where('status', 'rejected')->count());

        $ts = '1700000000';
        $v1 = hash_hmac('sha256', "id:9001;request-id:req-42;ts:{$ts};", 'whsec');
        $this->notify('payment', '9001', ['x-signature' => "ts={$ts},v1={$v1}", 'x-request-id' => 'req-42'])->assertOk();

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
    }

    public function test_authorized_payment_pays_the_renewal_of_a_card_subscriber(): void
    {
        $this->gateway();
        [, $sub, $invoice] = $this->checkout();
        $this->service->markInvoicePaid($invoice, []);
        $sub->refresh()->update(['auto_renew' => true, 'payment_method' => 'card', 'gateway_subscription_id' => 'PRE-1']);
        $end = $sub->current_period_end->copy();

        Carbon::setTestNow($end->copy()->subHour());
        $this->fakeHttp([
            'api.mercadopago.com/authorized_payments/AP-1' => Http::response(['id' => 'AP-1', 'preapproval_id' => 'PRE-1', 'status' => 'processed', 'payment' => ['id' => 777]]),
            'api.mercadopago.com/v1/payments/777' => Http::response(['id' => 777, 'status' => 'approved', 'payment_type_id' => 'credit_card', 'transaction_amount' => 129.0]),
            'api.mercadopago.com/preapproval/PRE-1' => Http::response(['status' => 'authorized']), // amount sync after payment
        ]);

        $this->notify('subscription_authorized_payment', 'AP-1')->assertOk();

        $this->assertSame(2, Invoice::where('subscription_id', $sub->id)->where('status', 'paid')->count());
        $this->assertTrue($sub->fresh()->current_period_end->gt($end));

        // replay is harmless
        $this->notify('subscription_authorized_payment', 'AP-1');
        $this->assertSame(2, Invoice::where('subscription_id', $sub->id)->where('status', 'paid')->count());
    }

    public function test_authorized_payment_confirms_the_first_charge_of_a_recurring_checkout(): void
    {
        $this->gateway();
        [$user, $sub, $invoice] = $this->checkout();
        $sub->update(['auto_renew' => true, 'payment_method' => 'card', 'gateway_subscription_id' => 'PRE-1']);
        $this->fakeHttp([
            'api.mercadopago.com/authorized_payments/AP-0' => Http::response(['id' => 'AP-0', 'preapproval_id' => 'PRE-1', 'payment' => ['id' => 700]]),
            'api.mercadopago.com/v1/payments/700' => Http::response(['id' => 700, 'status' => 'approved', 'payment_type_id' => 'credit_card', 'transaction_amount' => $invoice->amount_cents / 100]),
            'api.mercadopago.com/preapproval/PRE-1' => Http::response([]),
        ]);

        $this->notify('subscription_authorized_payment', 'AP-0')->assertOk();

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame(Subscription::ACTIVE, $sub->fresh()->status);
    }

    public function test_cancelled_preapproval_turns_automatic_renewal_off_and_warns_the_user(): void
    {
        $this->gateway();
        [$user, $sub] = $this->checkout();
        $sub->update(['auto_renew' => true, 'payment_method' => 'card', 'gateway_subscription_id' => 'PRE-3', 'gateway_status' => 'authorized']);
        $this->fakeHttp(['api.mercadopago.com/preapproval/PRE-3' => Http::response(['id' => 'PRE-3', 'status' => 'cancelled'])]);

        $this->notify('subscription_preapproval', 'PRE-3')->assertOk();

        $this->assertFalse($sub->fresh()->auto_renew);
        $this->assertSame('cancelled', $sub->fresh()->gateway_status);
        Notification::assertSentTo($user, BillingNotice::class);
    }

    public function test_notification_is_queued_for_retry_when_the_api_is_unreachable(): void
    {
        $this->gateway();
        [, , $invoice] = $this->checkout();
        $this->fakeHttp(['api.mercadopago.com/v1/payments/9' => Http::response([], 500)]);

        $event = WebhookEvent::create(['provider' => 'mercadopago', 'type' => 'payment', 'resource_id' => '9', 'status' => 'received']);

        try {
            (new \App\Jobs\ProcessMercadoPagoEventJob($event->id))->handle(app(\App\Services\Payments\MercadoPagoWebhookHandler::class));
            $this->fail('the job must throw so the queue retries it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be fetched', $e->getMessage());
        }

        $this->assertTrue($invoice->fresh()->isOpen());
        $this->assertSame('received', $event->fresh()->status, 'stays pending for the next attempt');

        // after the last attempt the failure is recorded
        (new \App\Jobs\ProcessMercadoPagoEventJob($event->id))->failed($e);
        $this->assertSame('failed', $event->fresh()->status);
    }

    public function test_notifications_without_type_or_id_and_unused_types_are_harmless(): void
    {
        $this->gateway();
        $this->fakeHttp(['*' => Http::response([], 500)]);

        $this->postJson('/api/webhooks/mercadopago', [])->assertOk()->assertJson(['status' => 'ignored']);
        $this->notify('point_integration_wh', '1')->assertOk();

        $this->assertSame('ignored', WebhookEvent::first()->status);
        Http::assertNothingSent();
    }
}
