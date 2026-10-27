<?php

namespace Tests\Feature;

use App\Jobs\ConfirmInstanceDownJob;
use App\Jobs\EnviarMensagemJob;
use App\Jobs\ProcessEvolutionWebhookJob;
use App\Listeners\NotifyQueueBacklog;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsappJob;
use App\Notifications\InstanceAlert;
use App\Services\Instances\InstanceHealth;
use App\Services\Instances\InstanceSendGate;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class InstanceHealthTest extends TestCase
{
    use CreatesTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
        config(['services.whatsapp.url' => 'evo.test', 'services.whatsapp.port' => '8080', 'services.whatsapp.protocol' => 'http',
            'whatsapp_queue.pacing.enabled' => true, 'whatsapp_queue.pacing.cooldown_min' => 30, 'whatsapp_queue.pacing.cooldown_max' => 30]);
    }

    private function user(string $name = 'ana', string $role = 'user'): User
    {
        $u = User::create(['name' => $name, 'email' => "$name@test.dev", 'username' => $name, 'password' => 'x']);
        if ($role !== 'user') {
            $u->forceFill(['role' => $role])->save();
        }

        return $u;
    }

    private function mkInstance(User $u, string $name = 'i1', string $status = 'connected'): Instance
    {
        return Instance::create(['user_id' => $u->id, 'name' => $name, 'instance_name' => $name . $u->id, 'status' => $status]);
    }

    private function waiting(User $u, int $n = 2, ?Instance $i = null): void
    {
        for ($k = 0; $k < $n; $k++) {
            WhatsappJob::create(['user_id' => $u->id, 'instance_id' => $i?->id, 'endpoint' => 'x', 'status' => 'fila', 'tentativas' => 0, 'payload' => []]);
        }
    }

    private function evoState(string $state): void
    {
        Http::fake([
            'evo.test:8080/instance/connectionState/*' => Http::response(['instance' => ['state' => $state]]),
            'evo.test:8080/instance/fetchInstances' => Http::response([]),
        ]);
    }

    // ---- gate ------------------------------------------------------------------------------------------------

    public function test_gate_lets_one_worker_in_and_makes_the_next_wait(): void
    {
        $gate = app(InstanceSendGate::class);

        [$ok, , $lock] = $gate->enter(1);
        $this->assertTrue($ok);

        [$second, $wait] = $gate->enter(1);   // same session while the first is sending
        $this->assertFalse($second);
        $this->assertGreaterThan(0, $wait);

        $gate->leave(1, $lock, true);
        [$third, $wait3] = $gate->enter(1);   // lock free, but the cool-down is running
        $this->assertFalse($third);
        $this->assertGreaterThanOrEqual(30, $wait3);

        [$other] = $gate->enter(2);           // another session is independent
        $this->assertTrue($other);
    }

    public function test_gate_failed_send_does_not_start_a_cooldown(): void
    {
        $gate = app(InstanceSendGate::class);
        [, , $lock] = $gate->enter(1);
        $gate->leave(1, $lock, false);

        $this->assertTrue($gate->enter(1)[0]);
    }

    public function test_job_that_cannot_enter_is_rescheduled_not_slept(): void
    {
        Queue::fake();
        $u = $this->user();
        $i = $this->mkInstance($u);
        $contact = \App\Models\Contact::create(['name' => 'c', 'contact' => '5595', 'user_id' => $u->id]);
        $job = WhatsappJob::create(['user_id' => $u->id, 'contact_id' => $contact->id, 'instance_id' => $i->id, 'endpoint' => '/message/sendText/', 'status' => 'fila', 'tentativas' => 0, 'payload' => ['number' => '5595', 'text' => 'hi']]);
        Http::fake();

        $this->travelTo(now('America/Sao_Paulo')->next(\Carbon\Carbon::TUESDAY)->setTime(11, 0));
        Cache::put("whatsapp:session:{$i->id}:next_send_at", time() + 120, 200);
        (new EnviarMensagemJob($job))->handle();

        Http::assertNothingSent();
        Queue::assertPushed(EnviarMensagemJob::class, 1);
    }

    // ---- alerts ----------------------------------------------------------------------------------------------

    public function test_webhook_close_marks_down_and_schedules_one_confirmation(): void
    {
        Queue::fake();
        $u = $this->user();
        $i = $this->mkInstance($u);

        foreach ([1, 2, 3] as $_) {
            (new ProcessEvolutionWebhookJob(['event' => 'connection.update', 'instance' => $i->instance_name, 'data' => ['state' => 'close']]))->handle();
        }

        $this->assertSame('disconnected', $i->fresh()->status);
        $this->assertNotNull($i->fresh()->disconnected_at);
        Queue::assertPushed(ConfirmInstanceDownJob::class, 1);
    }

    public function test_confirmed_outage_with_waiting_messages_notifies_owner_once(): void
    {
        Notification::fake();
        $u = $this->user();
        $i = $this->mkInstance($u, 'i1', 'disconnected');
        $i->forceFill(['disconnected_at' => now()->subMinutes(5)])->save();
        $this->waiting($u, 3, $i);
        $this->evoState('close');

        $health = app(InstanceHealth::class);
        $health->confirmDown($i->id);
        $health->confirmDown($i->id);   // second confirmation of the same outage

        Notification::assertSentToTimes($u, InstanceAlert::class, 1);
        Notification::assertSentTo($u, InstanceAlert::class, fn ($n) => $n->type === 'instance_down' && $n->data['pending'] === 3);
        $this->assertNotNull($i->fresh()->down_alerted_at);
    }

    public function test_flap_that_recovered_before_confirmation_sends_nothing(): void
    {
        Notification::fake();
        $u = $this->user();
        $i = $this->mkInstance($u, 'i1', 'disconnected');
        $this->waiting($u);
        $this->evoState('open');

        app(InstanceHealth::class)->confirmDown($i->id);

        Notification::assertNothingSent();
        $this->assertSame('connected', $i->fresh()->status);
    }

    public function test_idle_number_going_offline_is_not_reported(): void
    {
        Notification::fake();
        $u = $this->user();
        $i = $this->mkInstance($u, 'i1', 'disconnected');
        $this->evoState('close');

        app(InstanceHealth::class)->confirmDown($i->id);

        Notification::assertNothingSent();
    }

    public function test_recently_active_number_is_reported_even_with_empty_queue(): void
    {
        Notification::fake();
        $u = $this->user();
        $i = $this->mkInstance($u, 'i1', 'disconnected');
        WhatsappJob::create(['user_id' => $u->id, 'instance_id' => $i->id, 'endpoint' => 'x', 'status' => 'processado', 'tentativas' => 1, 'payload' => []]);
        $this->evoState('close');

        app(InstanceHealth::class)->confirmDown($i->id);

        Notification::assertSentTo($u, InstanceAlert::class);
    }

    public function test_when_another_session_is_connected_only_this_sessions_messages_count(): void
    {
        Notification::fake();
        $u = $this->user();
        $dead = $this->mkInstance($u, 'dead', 'disconnected');
        $live = $this->mkInstance($u, 'live');
        $this->waiting($u, 5, $live);     // flowing through the healthy number
        $this->evoState('close');

        app(InstanceHealth::class)->confirmDown($dead->id);

        Notification::assertNothingSent();
    }

    public function test_unreachable_evolution_does_not_blame_the_number(): void
    {
        Notification::fake();
        $u = $this->user();
        $i = $this->mkInstance($u, 'i1', 'disconnected');
        $this->waiting($u);
        Http::fake(['*' => Http::response('boom', 502)]);

        app(InstanceHealth::class)->confirmDown($i->id);

        Notification::assertNothingSent();
    }

    public function test_reconnect_after_alert_sends_recovery_and_rearms(): void
    {
        Notification::fake();
        $u = $this->user();
        $i = $this->mkInstance($u, 'i1', 'disconnected');
        $this->waiting($u);
        $this->evoState('close');
        $health = app(InstanceHealth::class);
        $health->confirmDown($i->id);

        $health->report($i->fresh(), true);

        Notification::assertSentTo($u, InstanceAlert::class, fn ($n) => $n->type === 'instance_recovered');
        $this->assertNull($i->fresh()->down_alerted_at);

        $health->report($i->fresh(), false, true);   // goes down again -> new outage, new alert
        Notification::assertSentToTimes($u, InstanceAlert::class, 3);
    }

    public function test_failure_streak_asks_evolution_and_alerts(): void
    {
        Notification::fake();
        $u = $this->user();
        $i = $this->mkInstance($u);
        $this->waiting($u, 2, $i);
        $this->evoState('close');
        $health = app(InstanceHealth::class);

        $health->sendFailed($i, 'API error 500');
        $health->sendFailed($i, 'API error 500');
        Notification::assertNothingSent();

        $health->sendFailed($i, 'API error 500');   // third in a row
        $this->assertSame('disconnected', $i->fresh()->status);
        Notification::assertSentTo($u, InstanceAlert::class, fn ($n) => $n->type === 'instance_down');
    }

    public function test_successful_send_resets_the_streak(): void
    {
        $u = $this->user();
        $i = $this->mkInstance($u);
        $health = app(InstanceHealth::class);
        $health->sendFailed($i, 'x');
        $health->sendFailed($i, 'x');
        $health->sendSucceeded($i);

        $this->assertNull(Cache::get("instance:{$i->id}:fail_streak"));
    }

    // ---- scheduled check -------------------------------------------------------------------------------------

    public function test_check_catches_a_missed_webhook_for_a_busy_session(): void
    {
        Notification::fake();
        $u = $this->user();
        $i = $this->mkInstance($u);   // DB still says connected
        $this->waiting($u, 2, $i);
        $this->evoState('close');

        $this->artisan('instances:health')->assertExitCode(0);

        Notification::assertSentTo($u, InstanceAlert::class, fn ($n) => $n->type === 'instance_down');
        $this->assertSame('disconnected', $i->fresh()->status);
    }

    public function test_evolution_outage_notifies_admins_once_and_announces_recovery(): void
    {
        Notification::fake();
        $admin = $this->user('boss', 'admin');
        $this->user('ana');
        $up = false;
        Http::fake(function () use (&$up) { return $up ? Http::response([]) : Http::response('', 503); });

        $this->artisan('instances:health')->assertExitCode(1);
        $this->artisan('instances:health')->assertExitCode(1);
        Notification::assertSentToTimes($admin, InstanceAlert::class, 1);

        $up = true;
        $this->artisan('instances:health')->assertExitCode(0);
        Notification::assertSentTo($admin, InstanceAlert::class, fn ($n) => $n->type === 'evolution_recovered');
    }

    public function test_queue_backlog_alerts_admins_with_cooldown(): void
    {
        Notification::fake();
        $admin = $this->user('boss', 'admin');
        $listener = app(NotifyQueueBacklog::class);

        $listener->handle(new QueueBusy('redis', 'disparos', 900));
        $listener->handle(new QueueBusy('redis', 'disparos', 950));

        Notification::assertSentToTimes($admin, InstanceAlert::class, 1);
    }

    public function test_alert_renders_for_bell_and_mail(): void
    {
        $u = $this->user();
        $n = new InstanceAlert('instance_down', ['instance' => 'Loja', 'pending' => 4]);

        $this->assertSame(['database', 'mail'], $n->via($u));
        $arr = $n->toArray($u);
        $this->assertStringContainsString('Loja', $arr['title']);
        $this->assertStringContainsString('4', $arr['message']);
        $this->assertStringContainsString('Loja', $n->toMail($u)->subject);
    }
}
