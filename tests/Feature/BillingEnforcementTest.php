<?php

namespace Tests\Feature;

use App\Imports\ContactsImport;
use App\Jobs\EnviarMensagemJob;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsappJob;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class BillingEnforcementTest extends TestCase
{
    use CreatesTestSchema;

    private Entitlements $ent;
    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Notification::fake();
        Cache::flush();
        config(['billing.enabled' => true, 'whatsapp_queue.fairness' => 'session', 'whatsapp_queue.batch_size' => 50,
            'services.whatsapp' => ['protocol' => 'http', 'url' => 'evo.test', 'port' => 8080, 'apikey' => 'k']]);
        Carbon::setTestNow('2026-11-02 15:00:00'); // Monday 12:00 in São Paulo (business hours)

        $this->ent = app(Entitlements::class);
        $this->service = app(SubscriptionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name = 'u', ?string $plan = null, string $state = 'active', ?string $role = null): User
    {
        $user = User::create(['name' => $name, 'email' => "$name@t.dev", 'username' => $name, 'password' => 'x']);
        if ($role) {
            $user->forceFill(['role' => $role])->save(); // role is not mass assignable
        }

        if ($plan) {
            $p = Plan::where('slug', $plan)->first();
            $sub = $this->service->grant($user, $p, 30);
            if ($state === 'suspended') {
                $sub->update(['status' => Subscription::SUSPENDED, 'suspended_at' => now()]);
            } elseif ($state === 'past_due') {
                $sub->update(['status' => Subscription::PAST_DUE, 'grace_ends_at' => now()->addDay()]);
            }
        }

        return $user;
    }

    private function connect(User $user, string $name): Instance
    {
        return Instance::create(['user_id' => $user->id, 'name' => $name, 'instance_name' => $name, 'status' => 'connected']);
    }

    private function jobs(User $user, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            WhatsappJob::create(['user_id' => $user->id, 'endpoint' => '5595', 'status' => 'pendente', 'payload' => []]);
        }
    }

    // ---- Entitlements ----------------------------------------------------------------------------

    public function test_billing_disabled_means_no_limits_for_anyone(): void
    {
        config(['billing.enabled' => false]);
        $free = $this->user('free');

        $this->assertTrue($this->ent->can($free, 'chat'));
        $this->assertNull($this->ent->remaining($free, 'messages_per_month'));
        $this->assertFalse($this->ent->isBlocked($free));
    }

    public function test_free_plan_has_the_free_limits_and_no_features(): void
    {
        $free = $this->user('free');

        $this->assertSame('free', $this->ent->plan($free)->slug);
        $this->assertFalse($this->ent->can($free, 'chat'));
        $this->assertFalse($this->ent->can($free, 'api_access'));
        $this->assertSame(1, $this->ent->limit($free, 'instances'));
        $this->assertSame(100, $this->ent->remaining($free, 'messages_per_month'));
    }

    public function test_paid_plans_unlock_their_features_and_admins_have_no_limits(): void
    {
        $starter = $this->user('s', 'starter');
        $pro = $this->user('p', 'pro');
        $admin = $this->user('a', null, 'active', 'admin');

        $this->assertTrue($this->ent->can($starter, 'chat'));
        $this->assertFalse($this->ent->can($starter, 'warmup'));
        $this->assertTrue($this->ent->can($pro, 'warmup'));
        $this->assertTrue($this->ent->can($admin, 'priority_support'));
        $this->assertNull($this->ent->limit($admin, 'contacts'));
    }

    public function test_grace_period_keeps_full_access_but_suspension_blocks_everything(): void
    {
        $pastDue = $this->user('pd', 'pro', 'past_due');
        $blocked = $this->user('bl', 'pro', 'suspended');

        $this->assertTrue($this->ent->can($pastDue, 'warmup'));
        $this->assertFalse($this->ent->isBlocked($pastDue));

        $this->assertTrue($this->ent->isBlocked($blocked));
        $this->assertFalse($this->ent->can($blocked, 'chat'));
        $this->assertSame(0, $this->ent->remaining($blocked, 'messages_per_month'));
        $this->assertSame('pro', $this->ent->plan($blocked)->slug, 'the screen still shows what the user pays for');
    }

    public function test_expired_or_pending_subscriptions_fall_back_to_free(): void
    {
        $user = $this->user('x', 'pro');
        Subscription::where('user_id', $user->id)->update(['status' => Subscription::EXPIRED]);
        $this->assertSame('free', $this->ent->plan($user)->slug);

        $pending = $this->user('y');
        $sub = $this->service->subscribe($pending, Plan::where('slug', 'pro')->first()); // trial -> pro
        $sub->update(['status' => Subscription::PENDING]);
        $this->assertSame('free', $this->ent->plan($pending)->slug, 'a paid plan still waiting for payment gives nothing yet');
    }

    public function test_monthly_counters_are_per_month(): void
    {
        $user = $this->user('m', 'starter');
        $this->ent->consume($user, 'messages', 5);
        $this->assertSame(5, $this->ent->used($user, 'messages_per_month'));

        Carbon::setTestNow('2026-12-01 15:00:00');
        $this->assertSame(0, $this->ent->used($user, 'messages_per_month'));
    }

    // ---- HTTP: features, blocking ------------------------------------------------------------------

    public function test_chat_requires_a_plan_with_chat_and_answers_402_for_json(): void
    {
        $free = $this->user('free');
        $inst = $this->connect($free, '5595001');

        $res = $this->actingAs($free)->getJson(route('chat.api.chats', ['instance' => $inst->id]))->assertStatus(402);
        $res->assertJsonPath('code', 'feature_not_in_plan')->assertJsonPath('key', 'chat');

        $starter = $this->user('st', 'starter');
        $inst2 = $this->connect($starter, '5595002');
        $this->actingAs($starter)->getJson(route('chat.api.chats', ['instance' => $inst2->id]))->assertOk();
    }

    public function test_web_pages_redirect_to_billing_with_a_message(): void
    {
        $free = $this->user('free');

        $this->actingAs($free)->get(route('chat.index'))
            ->assertRedirect(route('billing.index'))
            ->assertSessionHas('plan_error');
    }

    public function test_blocked_account_is_read_only_but_can_reach_billing(): void
    {
        $blocked = $this->user('bl', 'starter', 'suspended');
        $inst = $this->connect($blocked, '5595003');

        // reading still works
        $this->actingAs($blocked)->getJson(route('chat.api.chats', ['instance' => $inst->id]))->assertOk();
        $this->actingAs($blocked)->get(route('billing.index'))->assertOk();

        // anything that changes data is refused with the blocked code
        $this->actingAs($blocked)->postJson(route('chat.api.open'), ['phone' => '5595999'])
            ->assertStatus(402)->assertJsonPath('code', 'account_blocked');
        $this->actingAs($blocked)->post(route('contacts.store'), ['name' => 'A', 'contact' => '5595999'])
            ->assertRedirect(route('billing.index'));
        $this->assertSame(0, Contact::count());

        // but paying/choosing a plan is allowed
        $this->actingAs($blocked)->post(route('billing.subscribe'), ['plan' => 'pro'])->assertRedirect(route('billing.index'));
    }

    public function test_api_tokens_need_the_api_feature(): void
    {
        $starter = $this->user('st', 'starter');
        $pro = $this->user('pr', 'pro');

        $this->actingAs($starter)->post(route('profile.api-tokens.create'), ['name' => 't'])->assertRedirect(route('billing.index'));
        $this->actingAs($pro)->post(route('profile.api-tokens.create'), ['name' => 't'])->assertSessionMissing('plan_error');
    }

    // ---- Limits at creation ------------------------------------------------------------------------

    public function test_instances_are_limited_by_plan(): void
    {
        $free = $this->user('free');

        $this->actingAs($free)->post(route('instances.store'), ['name' => 'A', 'phone' => '5595111'])->assertRedirect(route('instances.index'));
        $this->assertSame(1, Instance::where('user_id', $free->id)->count());

        $this->actingAs($free)->post(route('instances.store'), ['name' => 'B', 'phone' => '5595222'])
            ->assertRedirect(route('billing.index'))->assertSessionHas('plan_error');
        $this->assertSame(1, Instance::where('user_id', $free->id)->count());
    }

    public function test_warmup_is_a_paid_feature_but_can_always_be_turned_off(): void
    {
        $starter = $this->user('st', 'starter');
        $inst = $this->connect($starter, '5595004');

        $this->actingAs($starter)->get(route('instances.warmup', $inst->id))->assertRedirect(route('billing.index'));
        $this->assertSame(0, (int) $inst->fresh()->warmup);

        $inst->update(['warmup' => 1]);
        $this->actingAs($starter)->get(route('instances.warmup', $inst->id))->assertRedirect(route('instances.index'));
        $this->assertSame(0, (int) $inst->fresh()->warmup);
    }

    public function test_contacts_are_limited_by_plan_including_spreadsheet_imports(): void
    {
        Plan::where('slug', 'free')->update(['limits' => ['instances' => 1, 'contacts' => 3, 'messages_per_month' => 100, 'ai_replies_per_month' => 0]]);
        $free = $this->user('free');
        foreach (range(1, 2) as $i) {
            Contact::create(['name' => "c$i", 'contact' => "559500$i", 'user_id' => $free->id]);
        }

        // 1 slot left: the import keeps one row and reports the others
        Auth::login($free);
        $import = new ContactsImport;
        $kept = array_filter([
            $import->model(['name' => 'a', 'contact' => '1']),
            $import->model(['name' => 'b', 'contact' => '2']),
            $import->model(['name' => 'c', 'contact' => '3']),
        ]);
        $this->assertCount(1, $kept);
        $this->assertSame(2, $import->skipped);

        Contact::create(['name' => 'c3', 'contact' => '5595003', 'user_id' => $free->id]);
        $this->actingAs($free)->post(route('contacts.store'), ['name' => 'X', 'contact' => '5595999'])
            ->assertRedirect(route('billing.index'));
        $this->assertSame(3, Contact::where('user_id', $free->id)->count());
    }

    // ---- Sending ------------------------------------------------------------------------------------

    public function test_queue_respects_the_monthly_message_quota(): void
    {
        Queue::fake();
        $free = $this->user('free');
        $this->connect($free, '5595010');
        $this->jobs($free, 150);
        $this->ent->consume($free, 'messages', 95); // 100 - 95 = 5 left

        $this->artisan('whatsapp:queue')->assertSuccessful();

        Queue::assertPushed(EnviarMensagemJob::class, 5);
    }

    public function test_quota_counts_messages_already_waiting_in_the_queue(): void
    {
        Queue::fake();
        $free = $this->user('free');
        $this->connect($free, '5595011');
        $this->jobs($free, 20);
        WhatsappJob::query()->limit(8)->update(['status' => 'fila']);
        $this->ent->consume($free, 'messages', 90); // 10 left, 8 already queued -> 2 more

        $this->artisan('whatsapp:queue');

        Queue::assertPushed(EnviarMensagemJob::class, 2);
    }

    public function test_blocked_users_and_exhausted_quotas_do_not_take_slots_from_others(): void
    {
        Queue::fake();
        $blocked = $this->user('bl', 'pro', 'suspended');
        $this->connect($blocked, '5595012');
        $ok = $this->user('ok', 'pro');
        $this->connect($ok, '5595013');
        $this->jobs($blocked, 100);
        $this->jobs($ok, 100);

        $this->artisan('whatsapp:queue');

        Queue::assertPushed(EnviarMensagemJob::class, 50);
        $this->assertSame(0, WhatsappJob::where('user_id', $blocked->id)->where('status', 'fila')->count());
        $this->assertSame(50, WhatsappJob::where('user_id', $ok->id)->where('status', 'fila')->count());
    }

    public function test_billing_disabled_leaves_the_queue_unchanged(): void
    {
        config(['billing.enabled' => false]);
        Queue::fake();
        $free = $this->user('free');
        $this->connect($free, '5595014');
        $this->jobs($free, 150);
        $this->ent->consume($free, 'messages', 100000);

        $this->artisan('whatsapp:queue');

        Queue::assertPushed(EnviarMensagemJob::class, 50);
    }

    public function test_send_job_counts_the_message_and_stops_when_the_quota_ran_out(): void
    {
        config(['app.env' => 'local']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'WA1']], 201)]);
        $free = $this->user('free');
        $this->connect($free, '5595015');
        $contact = Contact::create(['name' => 'c', 'contact' => '5595999', 'user_id' => $free->id]);
        $make = fn () => WhatsappJob::create([
            'user_id' => $free->id, 'contact_id' => $contact->id, 'endpoint' => 'message/sendText/', 'status' => 'fila',
            'payload' => ['number' => '5595999', 'text' => 'oi'],
        ]);

        $job = $make();
        (new EnviarMensagemJob($job))->handle();
        $this->assertSame('processado', $job->fresh()->status);
        $this->assertSame(1, $this->ent->used($free, 'messages_per_month'));

        $this->ent->consume($free, 'messages', 99); // quota used up
        $blockedJob = $make();
        Http::fake(); // forget the earlier fake: nothing may be sent now
        (new EnviarMensagemJob($blockedJob))->handle();

        $this->assertSame('pendente', $blockedJob->fresh()->status);
        $this->assertSame(0, $blockedJob->fresh()->tentativas);
        Http::assertNothingSent();
    }

    // ---- Billing screens ---------------------------------------------------------------------------

    public function test_billing_page_shows_plans_usage_and_invoices(): void
    {
        $user = $this->user('u');
        $this->service->subscribe($user, Plan::where('slug', 'pro')->first());

        $res = $this->actingAs($user)->get(route('billing.index'))->assertOk();

        foreach (['Starter', 'Pro', 'Business', 'INV-'] as $text) {
            $res->assertSee($text);
        }
    }

    public function test_user_can_subscribe_cancel_and_resume_from_the_screen(): void
    {
        $user = $this->user('u');

        $this->actingAs($user)->post(route('billing.subscribe'), ['plan' => 'starter', 'cycle' => 'yearly'])->assertRedirect(route('billing.index'));
        $sub = $this->service->current($user);
        $this->assertSame('trialing', $sub->status);
        $this->assertSame('yearly', $sub->cycle);

        $this->actingAs($user)->post(route('billing.cancel'));
        $this->assertTrue($sub->fresh()->cancel_at_period_end);

        $this->actingAs($user)->post(route('billing.resume'));
        $this->assertFalse($sub->fresh()->cancel_at_period_end);

        $this->actingAs($user)->post(route('billing.subscribe'), ['plan' => 'nope'])->assertSessionHasErrors('plan');
    }

    public function test_invoices_are_private_to_their_owner(): void
    {
        $a = $this->user('a');
        $b = $this->user('b');
        $invoice = $this->service->subscribe($a, Plan::where('slug', 'pro')->first())->openInvoice();

        $this->actingAs($a)->get(route('billing.invoice', $invoice))->assertOk()->assertSee($invoice->number);
        $this->actingAs($b)->get(route('billing.invoice', $invoice))->assertForbidden();
    }

    public function test_admin_can_confirm_a_payment_and_grant_plans_others_cannot(): void
    {
        $admin = $this->user('adm', null, 'active', 'admin');
        $customer = $this->user('cus');
        $invoice = $this->service->subscribe($customer, Plan::where('slug', 'pro')->first())->openInvoice();

        $this->actingAs($customer)->post(route('admin.billing.paid', $invoice))->assertRedirect('/');
        $this->assertSame(Invoice::OPEN, $invoice->fresh()->status);

        $this->actingAs($admin)->get(route('admin.billing.index'))->assertOk();
        $this->actingAs($admin)->post(route('admin.billing.paid', $invoice))->assertRedirect();
        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame('active', $this->service->current($customer)->status);

        $this->actingAs($admin)->post(route('admin.billing.grant'), ['user_id' => $customer->id, 'plan' => 'business', 'days' => 15])->assertRedirect();
        $this->assertSame('business', $this->ent->plan($customer)->slug);
    }

    public function test_dashboard_shows_the_plan_card_and_the_landing_page_shows_pricing(): void
    {
        $user = $this->user('u', 'pro');

        $this->actingAs($user)->get('/')->assertOk()->assertSee('Your plan')->assertSee('Pro')->assertSee(route('billing.index'), false);

        config(['billing.enabled' => false]);
        $this->actingAs($user)->get('/')->assertOk()->assertSee('Billing is not enabled yet');

        auth()->logout();
        $this->get('/')->assertOk()->assertSee('Starter')->assertSee('Business')->assertSee('R$ 129');
    }

    // ---- Commands -----------------------------------------------------------------------------------

    public function test_billing_commands(): void
    {
        $user = $this->user('u');

        $this->artisan('billing:sync-plans')->assertSuccessful();
        $this->artisan('billing:grant', ['user' => 'u@t.dev', 'plan' => 'pro', '--days' => 5])->assertSuccessful();
        $this->assertSame('pro', $this->ent->plan($user)->slug);
        $this->artisan('billing:grant', ['user' => 'u@t.dev', 'plan' => 'nope'])->assertFailed();

        $other = $this->user('o');
        $this->artisan('billing:grant', ['user' => 'all', 'plan' => 'starter'])->assertSuccessful();
        $this->assertSame('starter', $this->ent->plan($other)->slug);
        $this->assertSame('pro', $this->ent->plan($user)->slug, 'users that already have a subscription are left alone');

        Carbon::setTestNow('2026-11-08 00:00:00'); // 5-day grant ended on the 7th
        $this->artisan('billing:run')->assertSuccessful();
        $this->assertSame('past_due', $this->service->current($user)->status);
    }

    public function test_sync_plans_never_overwrites_edited_plans_unless_forced(): void
    {
        Plan::where('slug', 'pro')->update(['price_cents' => 1]);

        Plan::syncFromConfig();
        $this->assertSame(1, Plan::where('slug', 'pro')->value('price_cents'));

        Plan::syncFromConfig(true);
        $this->assertSame(12900, Plan::where('slug', 'pro')->value('price_cents'));
    }
}
