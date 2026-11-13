<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\Currencies;
use App\Services\Billing\SubscriptionService;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\PaymentService;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Payment currency chosen per customer: conversion at invoicing, Pix only in BRL, providers filtered by currency. */
class PaymentCurrencyTest extends TestCase
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
        config(['billing.enabled' => true, 'billing.currency' => 'BRL', 'app.url' => 'https://painel.test',
            'billing.currencies' => ['USD' => ['symbol' => 'US$', 'rate' => 0.2], 'EUR' => ['symbol' => '€', 'rate' => 0]]]);
        Carbon::setTestNow('2026-11-02 15:00:00');
        $this->service = app(SubscriptionService::class);
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enable(string $provider, array $extra = []): void
    {
        $g = PaymentGateway::for($provider);
        $g->forceFill(['enabled' => true, 'access_token' => 'sk_test_1', 'webhook_secret' => 'whsec'] + $extra);
        $g->settings = PaymentGateway::DEFAULTS;
        $g->save();
    }

    private function user(string $name = 'ana'): User
    {
        return User::create(['name' => 'Ana Silva', 'email' => "{$name}@t.dev", 'username' => $name, 'password' => 'x']);
    }

    private function choose(User $user, string $code)
    {
        return $this->actingAs($user->fresh())->post(route('billing.currency'), ['currency' => $code]);
    }

    public function test_conversion_uses_the_configured_rate_and_never_rounds_a_price_to_zero(): void
    {
        $this->assertSame(['BRL', 'USD'], array_keys(Currencies::all()), 'a currency with rate 0 is withdrawn');
        $this->assertSame(2000, Currencies::fromBase(10000, 'USD'));
        $this->assertSame(10000, Currencies::toBase(2000, 'USD'));
        $this->assertSame(1, Currencies::fromBase(1, 'USD'));
        $this->assertSame(0, Currencies::fromBase(0, 'USD'));
        $this->assertFalse(Currencies::pixAllowed('USD'));
        $this->assertTrue(Currencies::pixAllowed('BRL'));
    }

    public function test_only_currencies_some_ready_gateway_charges_are_offered(): void
    {
        $user = $this->user();
        $this->enable('asaas', ['access_token' => 'k']);   // BRL only

        $this->choose($user, 'USD')->assertSessionHasErrors('currency');
        $this->assertNull($user->fresh()->billing_currency);

        $this->enable('stripe');
        $this->choose($user, 'USD')->assertRedirect(route('billing.index'));
        $this->assertSame('USD', $user->fresh()->billing_currency);
    }

    public function test_a_currency_that_is_not_configured_is_refused(): void
    {
        $this->enable('stripe');
        $user = $this->user();

        $this->choose($user, 'JPY')->assertSessionHasErrors('currency');
        $this->choose($user, 'EUR')->assertSessionHasErrors('currency');
    }

    public function test_new_invoices_are_issued_in_the_chosen_currency(): void
    {
        $this->enable('stripe');
        $user = $this->user();
        $this->choose($user, 'USD');

        $plan = Plan::where('slug', 'pro')->first();
        $sub = $this->service->subscribe($user->fresh(), $plan, 'monthly');
        $invoice = $sub->openInvoice();

        $this->assertSame('USD', $invoice->currency);
        $this->assertSame((int) round($plan->priceFor('monthly') * 0.2), $invoice->amount_cents);
    }

    public function test_changing_currency_reissues_the_open_invoice_and_warns_about_pix(): void
    {
        $this->enable('stripe');
        $user = $this->user();
        $plan = Plan::where('slug', 'pro')->first();
        $sub = $this->service->subscribe($user, $plan, 'monthly');
        $old = $sub->openInvoice();
        $this->assertSame('BRL', $old->currency);

        $this->choose($user, 'USD')->assertRedirect(route('billing.index'))
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'Pix is only available for payments in BRL'));

        $this->assertSame(Invoice::VOID, $old->fresh()->status);
        $new = $sub->fresh()->openInvoice();
        $this->assertSame('USD', $new->currency);
        $this->assertSame((int) round($old->amount_cents * 0.2), $new->amount_cents);
        $this->assertEquals($old->period_end, $new->period_end);

        // and back to BRL
        $this->choose($user, 'BRL')->assertRedirect(route('billing.index'));
        $this->assertSame('BRL', $sub->fresh()->openInvoice()->currency);
    }

    public function test_currency_cannot_change_while_a_payment_is_in_progress_on_the_open_invoice(): void
    {
        $this->enable('stripe');
        $user = $this->user();
        $sub = $this->service->subscribe($user, Plan::where('slug', 'pro')->first(), 'monthly');
        $sub->openInvoice()->update(['gateway_invoice_id' => 'cs_pending']);

        $this->choose($user, 'USD')->assertSessionHasErrors('currency');
        $this->assertNull($user->fresh()->billing_currency);
    }

    public function test_pix_is_not_offered_and_is_refused_for_a_non_brl_invoice(): void
    {
        $this->enable('stripe');
        $user = $this->user();
        $this->choose($user, 'USD');
        $invoice = $this->service->subscribe($user->fresh(), Plan::where('slug', 'pro')->first(), 'monthly')->openInvoice();

        $page = $this->actingAs($user->fresh())->get(route('billing.invoice', $invoice))->assertOk();
        $page->assertSee('Pix is only available for payments in BRL', false)->assertDontSee('id="mPix"', false)->assertSee('id="mCard"', false);

        Http::swap(new Factory);
        Http::fake();
        $this->actingAs($user->fresh())->post(route('billing.pay', $invoice), ['method' => 'pix', 'gateway' => 'stripe', 'document' => self::CPF, 'recurring' => 0])
            ->assertSessionHasErrors('method');
        Http::assertNothingSent();
    }

    public function test_brl_invoice_keeps_pix(): void
    {
        $this->enable('stripe');
        $user = $this->user();
        $invoice = $this->service->subscribe($user, Plan::where('slug', 'pro')->first(), 'monthly')->openInvoice();

        $this->actingAs($user)->get(route('billing.invoice', $invoice))->assertOk()->assertSee('id="mPix"', false)
            ->assertDontSee('Pix is only available for payments in BRL', false);
    }

    public function test_brl_only_providers_are_not_offered_for_a_foreign_currency_invoice(): void
    {
        $this->enable('asaas', ['access_token' => 'k']);
        $this->enable('stripe');
        $payments = app(PaymentService::class);

        $this->assertSame(['asaas', 'stripe'], array_keys($payments->available()));
        $this->assertSame(['stripe'], array_keys($payments->available('USD')));
        $this->assertSame('stripe', $payments->gateway('asaas', 'USD')->name(), 'a provider that cannot charge the currency is replaced');
    }

    public function test_no_gateway_for_the_currency_says_so(): void
    {
        $this->enable('asaas', ['access_token' => 'k']);
        $user = $this->user();
        $user->forceFill(['billing_currency' => 'USD'])->save();   // chosen while a provider existed
        $invoice = $this->service->subscribe($user->fresh(), Plan::where('slug', 'pro')->first(), 'monthly')->openInvoice();

        $this->actingAs($user->fresh())->get(route('billing.invoice', $invoice))->assertOk()
            ->assertSee('No enabled payment provider charges in USD right now.');
    }

    public function test_stripe_charges_in_the_invoice_currency(): void
    {
        $this->enable('stripe');
        $user = $this->user();
        $this->choose($user, 'USD');
        $invoice = $this->service->subscribe($user->fresh(), Plan::where('slug', 'pro')->first(), 'monthly')->openInvoice();
        Http::swap(new Factory);
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'])]);

        $this->actingAs($user->fresh())->post(route('billing.pay', $invoice), ['method' => 'card', 'gateway' => 'stripe', 'document' => self::CPF, 'recurring' => 0])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');

        Http::assertSent(fn ($q) => str_contains(urldecode($q->body()), 'price_data][currency]=usd')
            && str_contains(urldecode($q->body()), 'unit_amount]=' . $invoice->amount_cents));
    }

    public function test_changing_currency_turns_off_provider_side_automatic_renewal(): void
    {
        $this->enable('stripe');
        $user = $this->user();
        $sub = $this->service->subscribe($user, Plan::where('slug', 'pro')->first(), 'monthly');
        $sub->update(['gateway' => 'stripe', 'gateway_subscription_id' => 'sub_1', 'auto_renew' => true, 'payment_method' => 'card']);
        Http::swap(new Factory);
        Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'status' => 'canceled'])]);

        $this->choose($user, 'USD')->assertSessionHas('warning', fn ($w) => str_contains($w, 'Automatic renewal was turned off'));

        Http::assertSent(fn ($q) => $q->method() === 'DELETE' && str_ends_with($q->url(), '/v1/subscriptions/sub_1'));
        $this->assertFalse($sub->fresh()->auto_renew);
    }

    public function test_billing_page_shows_the_selector_converted_prices_and_the_pix_notice(): void
    {
        $this->enable('stripe');
        $user = $this->user();
        $plan = Plan::where('slug', 'pro')->first();

        $this->actingAs($user)->get(route('billing.index'))->assertOk()->assertSee('name="currency"', false)->assertSee('R$ ' . number_format($plan->price_cents / 100, 2, ',', '.'));

        $this->choose($user, 'USD');
        $this->actingAs($user->fresh())->get(route('billing.index'))->assertOk()
            ->assertSee('US$ ' . number_format(round($plan->price_cents * 0.2) / 100, 2, ',', '.'))
            ->assertSee('Pix is only available for payments in BRL. In USD you pay by card or PayPal.');
    }

    public function test_selector_is_hidden_when_only_brl_is_possible(): void
    {
        $this->enable('asaas', ['access_token' => 'k']);

        $this->actingAs($this->user())->get(route('billing.index'))->assertOk()->assertDontSee('name="currency"', false);
    }

    public function test_upgrade_credit_of_an_invoice_paid_in_another_currency_is_converted_back(): void
    {
        $this->enable('stripe');
        $user = $this->user();
        $this->choose($user, 'USD');
        $starter = Plan::where('slug', 'starter')->first();
        $pro = Plan::where('slug', 'pro')->first();
        $sub = $this->service->subscribe($user->fresh(), $starter, 'monthly');
        $first = $sub->openInvoice();
        $this->service->markInvoicePaid($first, ['payment_method' => 'card']);

        Carbon::setTestNow(now()->addDays(10));
        $sub = $this->service->subscribe($user->fresh(), $pro, 'monthly');
        $upgrade = $sub->openInvoice();

        $this->assertSame('USD', $upgrade->currency);
        $this->assertGreaterThan(0, $upgrade->credit_cents);
        $this->assertLessThan($first->amount_cents, $upgrade->credit_cents, 'credit is below what was paid');
        $this->assertSame(Currencies::fromBase($pro->priceFor('monthly'), 'USD') - $upgrade->credit_cents, $upgrade->amount_cents + 0 * 1);
    }
}
