<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\SubscriptionService;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\PayRequest;
use App\Services\Payments\PaymentResult;
use App\Services\Payments\PaymentService;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Asaas, Efí and AbacatePay (Mercado Pago is covered by PaymentGatewayTest). All provider calls are faked. */
class PaymentProvidersTest extends TestCase
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

    private function setup_provider(string $provider, array $over = [], array $settings = []): PaymentGateway
    {
        $g = PaymentGateway::for($provider);
        $g->forceFill(array_merge(['enabled' => true, 'access_token' => 'secret-key-1234', 'webhook_secret' => null], $over));
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

    /** @return array{0:User,1:\App\Models\Subscription,2:Invoice} */
    private function checkout(string $name = 'ana'): array
    {
        $user = $this->user($name);
        $sub = $this->service->subscribe($user, Plan::where('slug', 'pro')->first(), 'monthly');

        return [$user, $sub->fresh(), $sub->openInvoice()];
    }

    private function fakeHttp(array $map): void
    {
        Http::swap(new Factory);
        Http::fake($map);
    }

    private function req(string $method, bool $recurring = false): PayRequest
    {
        return new PayRequest($method, $recurring, 'ana@t.dev', 'Ana Silva', self::CPF);
    }

    private function pay(string $provider, string $method, bool $recurring = false): PaymentResult
    {
        [$user, , $invoice] = $this->checkout();

        return app(PaymentService::class)->pay($user, $invoice, $this->req($method, $recurring), $provider);
    }

    // ---- manager / selection ------------------------------------------------------------------------

    public function test_several_providers_can_be_ready_and_the_requested_one_wins(): void
    {
        $this->setup_provider('asaas');
        $this->setup_provider('abacatepay');
        $this->setup_provider('efi', ['public_key' => 'Client_Id_x'], ['certificate_path' => '', 'pix_key' => '']); // card only
        $m = app(GatewayManager::class);

        $this->assertSame(['asaas', 'efi', 'abacatepay'], array_keys($m->ready()));
        $this->assertSame('abacatepay', $m->active('abacatepay')->name());
        $this->assertSame('asaas', $m->active()->name(), 'default = first ready');
        $this->assertSame('asaas', $m->active('mercadopago')->name(), 'a provider that is not ready falls back');
        $this->assertSame(['card'], $m->driver('efi')->methods(), 'Efí Pix needs certificate + key');
    }

    public function test_provider_with_incomplete_credentials_is_not_ready(): void
    {
        $this->setup_provider('efi', ['public_key' => null]);   // client id missing
        $this->setup_provider('asaas', ['enabled' => false]);
        $this->assertSame([], app(GatewayManager::class)->ready());
        $this->assertNull(app(PaymentService::class)->gateway());
    }

    public function test_invoice_page_offers_a_choice_between_active_providers(): void
    {
        $this->setup_provider('asaas');
        $this->setup_provider('abacatepay');
        [$user, , $invoice] = $this->checkout();

        $page = $this->actingAs($user)->get(route('billing.invoice', $invoice))->assertOk();
        $page->assertSee('Asaas')->assertSee('AbacatePay')->assertSee('name="gateway" value="asaas"', false);

        $this->actingAs($user)->get(route('billing.invoice', ['invoice' => $invoice, 'gateway' => 'abacatepay']))
            ->assertOk()->assertSee('name="gateway" value="abacatepay"', false);
    }

    // ---- Asaas ----------------------------------------------------------------------------------------

    public function test_asaas_pix_creates_customer_payment_and_returns_the_qr_code(): void
    {
        $this->setup_provider('asaas');
        $this->fakeHttp([
            'api.asaas.com/v3/customers?*' => Http::response(['data' => []]),
            'api.asaas.com/v3/customers' => Http::response(['id' => 'cus_1']),
            'api.asaas.com/v3/payments/pay_1/pixQrCode' => Http::response(['payload' => '000201PIXASAAS', 'encodedImage' => 'QUJD', 'expirationDate' => '2026-11-03 23:59:59']),
            'api.asaas.com/v3/payments' => Http::response(['id' => 'pay_1', 'invoiceUrl' => 'https://asaas.com/i/1']),
        ]);

        [$user, , $invoice] = $this->checkout();
        $r = app(PaymentService::class)->pay($user, $invoice, $this->req('pix'), 'asaas');

        $this->assertSame(PaymentResult::PENDING, $r->status);
        $this->assertSame('000201PIXASAAS', $r->pix['qr_code']);
        Http::assertSent(fn ($q) => $q->url() === 'https://api.asaas.com/v3/customers' && $q['cpfCnpj'] === self::CPF && $q->hasHeader('access_token', 'secret-key-1234'));
        Http::assertSent(fn ($q) => $q->url() === 'https://api.asaas.com/v3/payments' && $q['billingType'] === 'PIX'
            && $q['customer'] === 'cus_1' && $q['externalReference'] === $invoice->number
            && abs($q['value'] - $invoice->amount_cents / 100) < 0.001);

        $invoice->refresh();
        $this->assertSame('asaas', $invoice->gateway);
        $this->assertSame('pay_1', $invoice->gateway_invoice_id);
        $this->assertTrue($invoice->isOpen());
    }

    public function test_asaas_card_goes_to_the_hosted_page_and_the_controller_redirects(): void
    {
        $this->setup_provider('asaas');
        $this->fakeHttp([
            'api.asaas.com/v3/customers?*' => Http::response(['data' => [['id' => 'cus_9']]]),
            'api.asaas.com/v3/payments' => Http::response(['id' => 'pay_2', 'invoiceUrl' => 'https://asaas.com/i/2']),
        ]);
        [$user, , $invoice] = $this->checkout();

        $this->actingAs($user)->post(route('billing.pay', $invoice), ['method' => 'card', 'gateway' => 'asaas', 'document' => self::CPF, 'recurring' => 0])
            ->assertRedirect('https://asaas.com/i/2');

        Http::assertSent(fn ($q) => $q->url() === 'https://api.asaas.com/v3/payments' && $q['billingType'] === 'CREDIT_CARD');
        $this->assertSame('https://asaas.com/i/2', $invoice->fresh()->gateway_payload['redirect_url']);
        $this->actingAs($user)->get(route('billing.invoice', $invoice))->assertSee('https://asaas.com/i/2', false);
    }

    public function test_hosted_card_cannot_be_combined_with_automatic_renewal(): void
    {
        $this->setup_provider('asaas');
        $this->fakeHttp(['*' => Http::response([], 500)]);
        [$user, , $invoice] = $this->checkout();

        $this->actingAs($user)->postJson(route('billing.pay', $invoice), ['method' => 'card', 'gateway' => 'asaas', 'document' => self::CPF, 'recurring' => 1])
            ->assertStatus(422)->assertJsonPath('status', 'error');
        Http::assertNothingSent();
    }

    public function test_asaas_pix_with_automatic_renewal_is_remembered_with_the_provider(): void
    {
        $this->setup_provider('asaas');
        $this->fakeHttp([
            'api.asaas.com/v3/customers?*' => Http::response(['data' => [['id' => 'cus_1']]]),
            'api.asaas.com/v3/payments/*/pixQrCode' => Http::response(['payload' => 'PIX', 'encodedImage' => 'QQ', 'expirationDate' => '2026-11-03 23:59:59']),
            'api.asaas.com/v3/payments' => Http::response(['id' => 'pay_3']),
        ]);
        [$user, $sub, $invoice] = $this->checkout();

        app(PaymentService::class)->pay($user, $invoice, $this->req('pix', true), 'asaas');

        $sub->refresh();
        $this->assertTrue($sub->auto_renew);
        $this->assertSame('asaas', $sub->gateway);
        $this->assertSame('pix', $sub->payment_method);
    }

    private function asaasWebhook(string $id, string $event = 'PAYMENT_RECEIVED', array $headers = ['asaas-access-token' => 'tok-tok-tok'])
    {
        return $this->withHeaders($headers)->postJson('/api/webhooks/asaas', ['id' => 'evt_1', 'event' => $event, 'payment' => ['id' => $id, 'status' => 'RECEIVED']]);
    }

    public function test_asaas_webhook_settles_after_rereading_the_payment(): void
    {
        $this->setup_provider('asaas', ['webhook_secret' => 'tok-tok-tok']);
        [, , $invoice] = $this->checkout();
        $this->fakeHttp(['api.asaas.com/v3/payments/pay_1' => Http::response([
            'id' => 'pay_1', 'status' => 'RECEIVED', 'billingType' => 'PIX', 'value' => $invoice->amount_cents / 100, 'externalReference' => $invoice->number])]);

        $this->asaasWebhook('pay_1')->assertOk()->assertJson(['status' => 'queued']);

        $invoice->refresh();
        $this->assertSame(Invoice::PAID, $invoice->status);
        $this->assertSame('asaas', $invoice->gateway);
        $this->assertSame('pix', $invoice->payment_method);
        $this->assertSame('processed', WebhookEvent::first()->status);

        // delivered again (at-least-once): still fine
        $this->asaasWebhook('pay_1')->assertOk();
        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
    }

    public function test_asaas_webhook_with_wrong_token_is_rejected_and_nothing_is_fetched(): void
    {
        $this->setup_provider('asaas', ['webhook_secret' => 'tok-tok-tok']);
        $this->fakeHttp(['*' => Http::response([], 500)]);

        $this->asaasWebhook('pay_1', 'PAYMENT_RECEIVED', ['asaas-access-token' => 'nope'])->assertStatus(401);

        $this->assertSame('rejected', WebhookEvent::first()->status);
        Http::assertNothingSent();
    }

    public function test_asaas_forged_notification_cannot_pay_an_invoice_the_provider_says_is_pending(): void
    {
        $this->setup_provider('asaas', ['webhook_secret' => 'tok-tok-tok']);
        [, , $invoice] = $this->checkout();
        $this->fakeHttp(['api.asaas.com/v3/payments/pay_1' => Http::response([
            'id' => 'pay_1', 'status' => 'PENDING', 'billingType' => 'PIX', 'value' => $invoice->amount_cents / 100, 'externalReference' => $invoice->number])]);

        $this->asaasWebhook('pay_1')->assertOk();

        $this->assertTrue($invoice->fresh()->isOpen());
    }

    public function test_asaas_payment_lower_than_the_invoice_is_not_applied(): void
    {
        $this->setup_provider('asaas', ['webhook_secret' => 'tok-tok-tok']);
        [, , $invoice] = $this->checkout();
        $this->fakeHttp(['api.asaas.com/v3/payments/pay_1' => Http::response([
            'id' => 'pay_1', 'status' => 'RECEIVED', 'billingType' => 'PIX', 'value' => 1.00, 'externalReference' => $invoice->number])]);

        $this->asaasWebhook('pay_1')->assertOk();

        $this->assertTrue($invoice->fresh()->isOpen());
        $this->assertStringContainsString('lower', (string) WebhookEvent::first()->note);
    }

    public function test_asaas_sandbox_uses_the_sandbox_host(): void
    {
        $this->setup_provider('asaas', [], ['sandbox' => true]);
        $this->fakeHttp(['api-sandbox.asaas.com/v3/myAccount/commercialInfo' => Http::response(['companyName' => 'ACME'])]);

        $r = app(GatewayManager::class)->driver('asaas')->testConnection();

        $this->assertTrue($r['ok']);
        $this->assertSame('ACME', $r['account']);
    }

    // ---- Efí --------------------------------------------------------------------------------------------

    private function efi(array $settings = []): string
    {
        $cert = tempnam(sys_get_temp_dir(), 'efi') . '.pem';
        file_put_contents($cert, 'fake-cert');
        $this->setup_provider('efi', ['public_key' => 'Client_Id_x', 'webhook_secret' => 'efi-secret'], array_merge(['certificate_path' => $cert, 'pix_key' => 'chave@pix.com'], $settings));

        return $cert;
    }

    public function test_efi_pix_creates_cob_with_certificate_and_returns_qr(): void
    {
        $this->efi();
        $this->fakeHttp([
            'pix.api.efipay.com.br/oauth/token' => Http::response(['access_token' => 'PIXTOKEN']),
            'pix.api.efipay.com.br/v2/cob/*' => Http::response(['status' => 'ATIVA', 'pixCopiaECola' => '000201PIXEFI', 'loc' => ['id' => 77]]),
            'pix.api.efipay.com.br/v2/loc/77/qrcode' => Http::response(['qrcode' => '000201PIXEFI', 'imagemQrcode' => 'data:image/png;base64,QUJD']),
        ]);

        [$user, , $invoice] = $this->checkout();
        $r = app(PaymentService::class)->pay($user, $invoice, $this->req('pix'), 'efi');

        $this->assertSame('000201PIXEFI', $r->pix['qr_code']);
        $this->assertSame('QUJD', $r->pix['qr_base64'], 'data URI prefix stripped');
        $this->assertMatchesRegularExpression('/^sama\d{8}[0-9a-f]{16}$/', $r->gatewayId);

        Http::assertSent(fn ($q) => str_contains($q->url(), '/v2/cob/' . $r->gatewayId) && $q->method() === 'PUT'
            && $q['chave'] === 'chave@pix.com' && $q['devedor']['cpf'] === self::CPF
            && $q['valor']['original'] === number_format($invoice->amount_cents / 100, 2, '.', '') && $q->hasHeader('Authorization', 'Bearer PIXTOKEN'));
        Http::assertSent(fn ($q) => str_contains($q->url(), '/oauth/token') && $q->hasHeader('Authorization'));
    }

    public function test_efi_pix_webhook_settles_by_txid_after_rereading_the_charge(): void
    {
        $this->efi();
        [, , $invoice] = $this->checkout();
        $txid = 'sama' . str_pad((string) $invoice->id, 8, '0', STR_PAD_LEFT) . 'abcdef0123456789';
        $this->fakeHttp([
            'pix.api.efipay.com.br/oauth/token' => Http::response(['access_token' => 'T']),
            'pix.api.efipay.com.br/v2/cob/' . $txid => Http::response(['status' => 'CONCLUIDA', 'valor' => ['original' => number_format($invoice->amount_cents / 100, 2, '.', '')]]),
        ]);

        // Efí appends /pix to the registered URL and keeps the query string
        $this->postJson('/api/webhooks/efi/pix?secret=efi-secret', ['pix' => [['txid' => $txid, 'valor' => '1.00', 'endToEndId' => 'E1']]])->assertOk();

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame('efi', $invoice->fresh()->gateway);
    }

    public function test_efi_webhook_without_the_secret_is_rejected_and_the_secret_is_never_stored(): void
    {
        $this->efi();
        $this->fakeHttp(['*' => Http::response([], 500)]);

        $this->postJson('/api/webhooks/efi/pix?secret=wrong', ['pix' => [['txid' => 'x']]])->assertStatus(401);
        $this->postJson('/api/webhooks/efi/pix?secret=wrong', ['secret' => 'efi-secret', 'pix' => []])->assertStatus(401);

        foreach (WebhookEvent::all() as $e) {
            $this->assertStringNotContainsString('efi-secret', json_encode($e->payload));
        }
        Http::assertNothingSent();
    }

    public function test_efi_card_uses_a_one_step_payment_link_and_notification_settles(): void
    {
        $this->efi();
        [$user, , $invoice] = $this->checkout();
        $this->fakeHttp([
            'cobrancas.api.efipay.com.br/v1/authorize' => Http::response(['access_token' => 'CT']),
            'cobrancas.api.efipay.com.br/v1/charge/one-step/link' => Http::response(['code' => 200, 'data' => ['charge_id' => 555, 'payment_url' => 'https://pagamento.efi/abc']]),
            'cobrancas.api.efipay.com.br/v1/notification/TOK1' => Http::response(['code' => 200, 'data' => [
                ['id' => 1, 'custom_id' => $invoice->number, 'identifiers' => ['charge_id' => 555], 'status' => ['current' => 'waiting', 'previous' => 'link'], 'type' => 'charge'],
                ['id' => 2, 'custom_id' => $invoice->number, 'identifiers' => ['charge_id' => 555], 'status' => ['current' => 'paid', 'previous' => 'waiting'], 'type' => 'charge', 'value' => $invoice->amount_cents],
            ]]),
        ]);

        $this->actingAs($user)->post(route('billing.pay', $invoice), ['method' => 'card', 'gateway' => 'efi', 'document' => self::CPF, 'recurring' => 0])
            ->assertRedirect('https://pagamento.efi/abc');

        Http::assertSent(fn ($q) => str_contains($q->url(), '/charge/one-step/link') && $q['metadata']['custom_id'] === $invoice->number
            && $q['metadata']['notification_url'] === 'https://painel.test/api/webhooks/efi?secret=efi-secret'
            && $q['items'][0]['value'] === $invoice->amount_cents && $q['settings']['payment_method'] === 'credit_card');

        $this->postJson('/api/webhooks/efi?secret=efi-secret', ['notification' => 'TOK1'])->assertOk();

        $invoice->refresh();
        $this->assertSame(Invoice::PAID, $invoice->status);
        $this->assertSame('card', $invoice->payment_method);
    }

    public function test_efi_sandbox_hosts_and_registering_the_pix_webhook(): void
    {
        $this->efi(['sandbox' => true]);
        $this->fakeHttp([
            'pix-h.api.efipay.com.br/oauth/token' => Http::response(['access_token' => 'T']),
            'pix-h.api.efipay.com.br/v2/webhook/*' => Http::response(['webhookUrl' => 'x']),
        ]);

        $r = app(GatewayManager::class)->driver('efi')->registerPixWebhook();

        $this->assertTrue($r['ok']);
        Http::assertSent(fn ($q) => $q->method() === 'PUT' && str_contains($q->url(), '/v2/webhook/chave%40pix.com')
            && $q['webhookUrl'] === 'https://painel.test/api/webhooks/efi?secret=efi-secret' && $q->hasHeader('x-skip-mtls-checking', 'true'));
    }

    // ---- AbacatePay -----------------------------------------------------------------------------------

    public function test_abacatepay_pix_and_check_via_webhook(): void
    {
        $this->setup_provider('abacatepay', ['webhook_secret' => 'abc-secret']);
        $this->fakeHttp([
            'api.abacatepay.com/v1/pixQrCode/create' => Http::response(['error' => null, 'data' => ['id' => 'pix_char_1', 'status' => 'PENDING', 'brCode' => '000201ABACATE', 'brCodeBase64' => 'data:image/png;base64,QUJD', 'expiresAt' => '2026-11-03T15:00:00.000Z']]),
            'api.abacatepay.com/v1/pixQrCode/check*' => Http::response(['error' => null, 'data' => ['status' => 'PAID']]),
        ]);

        [$user, , $invoice] = $this->checkout();
        $r = app(PaymentService::class)->pay($user, $invoice, $this->req('pix'), 'abacatepay');

        $this->assertSame('000201ABACATE', $r->pix['qr_code']);
        $this->assertSame('QUJD', $r->pix['qr_base64']);
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/pixQrCode/create') && $q['amount'] === $invoice->amount_cents
            && $q['metadata']['externalId'] === $invoice->number && $q->hasHeader('Authorization', 'Bearer secret-key-1234'));

        $this->postJson('/api/webhooks/abacatepay?secret=abc-secret', ['event' => 'transparent.completed', 'data' => ['pixQrCode' => ['id' => 'pix_char_1']]])->assertOk();

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame('abacatepay', $invoice->fresh()->gateway);
    }

    public function test_abacatepay_card_billing_redirect_and_paid_webhook(): void
    {
        $this->setup_provider('abacatepay', ['webhook_secret' => 'abc-secret']);
        [$user, , $invoice] = $this->checkout();
        $this->fakeHttp([
            'api.abacatepay.com/v1/billing/create' => Http::response(['error' => null, 'data' => ['id' => 'bill_1', 'url' => 'https://pay.abacatepay.com/bill_1', 'status' => 'PENDING']]),
            'api.abacatepay.com/v1/billing/list' => Http::response(['error' => null, 'data' => [
                ['id' => 'bill_0', 'status' => 'PAID', 'products' => [['externalId' => 'INV-OTHER', 'price' => 1, 'quantity' => 1]]],
                ['id' => 'bill_1', 'status' => 'PAID', 'products' => [['externalId' => $invoice->number, 'price' => $invoice->amount_cents, 'quantity' => 1]]],
            ]]),
        ]);

        $this->actingAs($user)->post(route('billing.pay', $invoice), ['method' => 'card', 'gateway' => 'abacatepay', 'document' => self::CPF, 'recurring' => 0])
            ->assertRedirect('https://pay.abacatepay.com/bill_1');
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/billing/create') && $q['methods'] === ['CARD'] && $q['frequency'] === 'ONE_TIME'
            && $q['products'][0]['externalId'] === $invoice->number && $q['products'][0]['price'] === $invoice->amount_cents);

        $this->postJson('/api/webhooks/abacatepay?secret=abc-secret', ['event' => 'billing.paid', 'data' => ['billing' => ['id' => 'bill_1']]])->assertOk();

        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame('card', $invoice->fresh()->payment_method);
    }

    public function test_abacatepay_unrelated_events_and_bad_secret(): void
    {
        $this->setup_provider('abacatepay', ['webhook_secret' => 'abc-secret']);
        $this->fakeHttp(['*' => Http::response([], 500)]);

        $this->postJson('/api/webhooks/abacatepay?secret=abc-secret', ['event' => 'billing.created', 'data' => ['billing' => ['id' => 'bill_1']]])->assertOk()->assertJson(['status' => 'ignored']);
        $this->postJson('/api/webhooks/abacatepay?secret=bad', ['event' => 'billing.paid', 'data' => ['billing' => ['id' => 'bill_1']]])->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_provider_outage_keeps_the_event_for_retry(): void
    {
        $this->setup_provider('abacatepay', ['webhook_secret' => 'abc-secret']);
        [, , $invoice] = $this->checkout();
        $this->fakeHttp(['api.abacatepay.com/*' => Http::response([], 503)]);

        try {
            $this->postJson('/api/webhooks/abacatepay?secret=abc-secret', ['event' => 'transparent.completed', 'data' => ['pixQrCode' => ['id' => 'pix_char_9']]]);
        } catch (\Throwable $e) {
            // sync queue rethrows the retry signal
        }

        // the sync queue of the tests has no retries, so it ends as failed; on a real queue it stays 'received' until the last attempt
        $this->assertContains(WebhookEvent::first()->status, ['received', 'failed']);
        $this->assertStringContainsString('could not be fetched', (string) WebhookEvent::first()->note);
        $this->assertTrue($invoice->fresh()->isOpen());
    }

    // ---- Admin ------------------------------------------------------------------------------------------

    public function test_admin_configures_each_provider_with_secrets_encrypted_and_masked(): void
    {
        $admin = $this->user('boss', 'admin');

        $this->actingAs($admin)->post('/admin/payment-gateway', [
            'provider' => 'asaas', 'enabled' => 1, 'access_token' => '$aact_secret_value_9999', 'webhook_secret' => 'tok-tok-tok-tok',
            'card_enabled' => 1, 'pix_enabled' => 1, 'recurrence_enabled' => 1, 'pix_expiration_minutes' => 120, 'sandbox' => 1,
        ])->assertRedirect(route('admin.gateway.edit', ['provider' => 'asaas']));

        $g = PaymentGateway::for('asaas')->fresh();
        $this->assertSame('$aact_secret_value_9999', $g->access_token);
        $this->assertNotSame('$aact_secret_value_9999', $g->getRawOriginal('access_token'));
        $this->assertTrue($g->isSandbox());
        $this->assertSame(['card', 'pix'], $g->methods());

        $page = $this->actingAs($admin)->get('/admin/payment-gateway?provider=asaas')->assertOk();
        $page->assertSee('Asaas')->assertSee('R$ 1,99')->assertDontSee('$aact_secret_value_9999', false)->assertDontSee('tok-tok-tok-tok', false);

        // blank keeps the stored secret
        $this->actingAs($admin)->post('/admin/payment-gateway', ['provider' => 'asaas', 'enabled' => 1, 'access_token' => '', 'card_enabled' => 1, 'pix_enabled' => 1, 'pix_expiration_minutes' => 120])->assertSessionHasNoErrors();
        $this->assertSame('$aact_secret_value_9999', PaymentGateway::for('asaas')->fresh()->access_token);

        // the other providers stay independent
        $this->assertFalse(PaymentGateway::for('efi')->fresh()->isReady());
    }

    public function test_admin_efi_needs_certificate_and_pix_key_for_pix_and_unknown_provider_is_404(): void
    {
        $admin = $this->user('boss', 'admin');

        $this->actingAs($admin)->post('/admin/payment-gateway', [
            'provider' => 'efi', 'enabled' => 1, 'access_token' => 'sec', 'public_key' => 'Client_Id_1', 'card_enabled' => 1, 'pix_enabled' => 1,
            'pix_expiration_minutes' => 60, 'certificate_path' => '', 'pix_key' => '',
        ])->assertSessionHas('warning');
        $this->assertSame(['card'], PaymentGateway::for('efi')->fresh()->methods());

        $this->actingAs($admin)->get('/admin/payment-gateway?provider=paypal')->assertNotFound();
        $this->actingAs($this->user('plain'))->post('/admin/payment-gateway', ['provider' => 'asaas'])->assertRedirect();
    }

    public function test_admin_test_connection_for_abacatepay(): void
    {
        $this->setup_provider('abacatepay');
        $this->fakeHttp(['api.abacatepay.com/v1/store/get' => Http::response(['data' => ['name' => 'Minha Loja']])]);

        $this->actingAs($this->user('boss', 'admin'))->post('/admin/payment-gateway/test', ['provider' => 'abacatepay'])
            ->assertSessionHas('success');
    }
}
