<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensagemJob;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsappJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class FairQueueTest extends TestCase
{
    use CreatesTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        config(['whatsapp_queue.batch_size' => 50, 'whatsapp_queue.fairness' => 'session', 'whatsapp_queue.inflight_window_hours' => 24]);
        Cache::flush();
    }

    private function user(string $name, int $sessions = 1, string $status = 'connected'): User
    {
        $user = User::create(['name' => $name, 'email' => "$name@test.dev", 'username' => $name, 'password' => 'x']);
        for ($i = 1; $i <= $sessions; $i++) {
            Instance::create(['user_id' => $user->id, 'name' => "$name-$i", 'instance_name' => "55{$user->id}00$i", 'status' => $status]);
        }

        return $user;
    }

    private function pending(User $user, int $count, string $status = 'pendente', int $tentativas = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            WhatsappJob::create(['user_id' => $user->id, 'endpoint' => '5595', 'status' => $status, 'tentativas' => $tentativas, 'payload' => []]);
        }
    }

    /** @return array<int,int> user_id => number of jobs pushed */
    private function pushedPerUser(): array
    {
        $out = [];
        foreach (Queue::pushed(EnviarMensagemJob::class) as $pushed) {
            $uid = $this->jobOf($pushed)->user_id;
            $out[$uid] = ($out[$uid] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    private function jobOf($pushedJob): WhatsappJob
    {
        $ref = new \ReflectionProperty($pushedJob, 'jobModel');
        $ref->setAccessible(true);

        return $ref->getValue($pushedJob);
    }

    public function test_batch_is_split_between_users_even_when_one_has_older_and_bigger_backlog(): void
    {
        Queue::fake();
        $big = $this->user('big');
        $small = $this->user('small');
        $this->pending($big, 200);   // older rows: legacy FIFO would take all 50 slots
        $this->pending($small, 30);

        $this->artisan('whatsapp:queue')->assertSuccessful();

        Queue::assertPushed(EnviarMensagemJob::class, 50);
        Queue::assertPushedOn('disparos', EnviarMensagemJob::class);
        $this->assertSame([$big->id => 25, $small->id => 25], $this->pushedPerUser());
    }

    public function test_dispatched_rows_are_marked_queued_and_oldest_first_per_user(): void
    {
        Queue::fake();
        $a = $this->user('a');
        $b = $this->user('b');
        $this->pending($a, 60);
        $this->pending($b, 60);

        $this->artisan('whatsapp:queue');

        $this->assertSame(50, WhatsappJob::where('status', 'fila')->count());
        $this->assertSame(70, WhatsappJob::where('status', 'pendente')->count());
        // Oldest 25 of each user were taken
        $this->assertSame(25, WhatsappJob::where('user_id', $a->id)->where('status', 'fila')->where('id', '<=', 25)->count());
        $this->assertSame(0, WhatsappJob::where('status', 'fila')->whereNull('instance_id')->count());
    }

    public function test_more_sessions_means_a_bigger_share_in_session_mode_and_equal_in_user_mode(): void
    {
        Queue::fake();
        $many = $this->user('many', 3);
        $one = $this->user('one', 1);
        $this->pending($many, 100);
        $this->pending($one, 100);

        $this->artisan('whatsapp:queue')->assertSuccessful();
        $session = $this->pushedPerUser();
        $this->assertGreaterThan($session[$one->id] * 2, $session[$many->id]);

        WhatsappJob::query()->update(['status' => 'pendente']);
        Queue::fake();

        $this->artisan('whatsapp:queue', ['--mode' => 'user'])->assertSuccessful();
        $this->assertSame([$many->id => 25, $one->id => 25], $this->pushedPerUser());
    }

    public function test_slots_of_one_user_are_spread_over_their_sessions(): void
    {
        Queue::fake();
        $u = $this->user('multi', 3);
        $this->pending($u, 90);

        $this->artisan('whatsapp:queue', ['--batch' => 30]);

        $perInstance = WhatsappJob::where('status', 'fila')->selectRaw('instance_id, count(*) c')->groupBy('instance_id')->pluck('c', 'instance_id');
        $this->assertCount(3, $perInstance);
        $this->assertSame([10, 10, 10], array_values($perInstance->all()));
    }

    public function test_messages_already_waiting_count_against_their_owner(): void
    {
        Queue::fake();
        $a = $this->user('a');
        $b = $this->user('b');
        $this->pending($a, 40, 'fila');   // earlier batch still waiting
        $this->pending($a, 100);
        $this->pending($b, 100);

        $this->artisan('whatsapp:queue');

        $this->assertSame([$a->id => 5, $b->id => 45], $this->pushedPerUser());
    }

    public function test_stale_queued_rows_do_not_count_as_waiting(): void
    {
        Queue::fake();
        $a = $this->user('a');
        $b = $this->user('b');
        $this->pending($a, 40, 'fila');
        WhatsappJob::where('status', 'fila')->update(['updated_at' => now()->subDays(3)]);
        $this->pending($a, 100);
        $this->pending($b, 100);

        $this->artisan('whatsapp:queue');

        $this->assertSame([$a->id => 25, $b->id => 25], $this->pushedPerUser());
    }

    public function test_ineligible_rows_and_users_without_a_connected_session_are_left_alone(): void
    {
        Queue::fake();
        $ok = $this->user('ok');
        $offline = $this->user('offline', 1, 'disconnected');
        $this->pending($ok, 5);
        $this->pending($ok, 5, 'erro', 1);        // retryable
        $this->pending($ok, 5, 'erro', 3);        // gave up
        $this->pending($ok, 5, 'processado');
        $this->pending($offline, 20);

        $this->artisan('whatsapp:queue')->assertSuccessful();

        $this->assertSame([$ok->id => 10], $this->pushedPerUser());
        $this->assertSame(20, WhatsappJob::where('user_id', $offline->id)->where('status', 'pendente')->where('tentativas', 0)->count());
        $this->assertSame(5, WhatsappJob::where('status', 'erro')->where('tentativas', 3)->count());
    }

    public function test_fifo_mode_keeps_the_legacy_behaviour(): void
    {
        Queue::fake();
        $big = $this->user('big');
        $small = $this->user('small');
        $this->pending($big, 80);
        $this->pending($small, 80);

        $this->artisan('whatsapp:queue', ['--mode' => 'fifo']);

        $this->assertSame([$big->id => 50], $this->pushedPerUser());
    }

    public function test_dry_run_prints_the_plan_and_changes_nothing(): void
    {
        Queue::fake();
        $a = $this->user('a');
        $b = $this->user('b');
        $this->pending($a, 60);
        $this->pending($b, 60);

        $this->artisan('whatsapp:queue', ['--dry-run' => true])
            ->expectsOutputToContain('Selected')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(0, WhatsappJob::where('status', 'fila')->count());
    }

    public function test_dispatch_order_interleaves_users(): void
    {
        Queue::fake();
        $a = $this->user('a');
        $b = $this->user('b');
        $this->pending($a, 60);
        $this->pending($b, 60);

        $this->artisan('whatsapp:queue');

        $order = array_map(fn ($p) => $this->jobOf($p)->user_id, Queue::pushed(EnviarMensagemJob::class)->all());
        $runs = 1;
        $longest = 1;
        for ($i = 1; $i < count($order); $i++) {
            $runs = $order[$i] === $order[$i - 1] ? $runs + 1 : 1;
            $longest = max($longest, $runs);
        }
        $this->assertLessThanOrEqual(2, $longest);
    }

    public function test_nothing_to_do_and_panic_mode(): void
    {
        Queue::fake();
        $this->artisan('whatsapp:queue')->assertSuccessful();
        Queue::assertNothingPushed();

        $u = $this->user('u');
        $this->pending($u, 3);
        Cache::put('system_panic_mode', true);
        $this->artisan('whatsapp:queue')->assertExitCode(1);
        Queue::assertNothingPushed();
    }

    public function test_send_job_uses_the_session_assigned_by_the_planner(): void
    {
        $u = $this->user('u', 2);
        [$first, $second] = Instance::where('user_id', $u->id)->orderBy('id')->get()->all();
        $job = WhatsappJob::create(['user_id' => $u->id, 'endpoint' => '5595', 'payload' => [], 'instance_id' => $second->id]);

        $resolve = new \ReflectionMethod(EnviarMensagemJob::class, 'resolveInstance');
        $resolve->setAccessible(true);
        $sender = (new \ReflectionClass(EnviarMensagemJob::class))->newInstanceWithoutConstructor();

        // assigned and connected -> that one, every time
        for ($i = 0; $i < 8; $i++) {
            $this->assertSame($second->id, $resolve->invoke($sender, $u, $job)->id);
        }

        // assigned session dropped -> falls back to another connected one
        $second->update(['status' => 'disconnected']);
        $this->assertSame($first->id, $resolve->invoke($sender, $u, $job->fresh())->id);

        // session of someone else is never used
        $other = $this->user('other');
        $foreign = Instance::where('user_id', $other->id)->first();
        $job->instance_id = $foreign->id;
        $this->assertSame($first->id, $resolve->invoke($sender, $u, $job)->id);
    }
}
