<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensagemJob;
use App\Models\Instance;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsappJob;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class PlanQueuePriorityTest extends TestCase
{
    use CreatesTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
        Queue::fake();
        Plan::syncFromConfig();
        config(['billing.enabled' => true, 'whatsapp_queue.batch_size' => 50, 'whatsapp_queue.fairness' => 'session',
            'whatsapp_queue.priority.enabled' => true, 'whatsapp_queue.priority.mode' => 'weighted']);
    }

    private function account(string $name, string $plan): User
    {
        $u = User::create(['name' => $name, 'email' => "$name@t.dev", 'username' => $name, 'password' => 'x']);
        app(SubscriptionService::class)->grant($u, Plan::where('slug', $plan)->first(), 30);
        Instance::create(['user_id' => $u->id, 'name' => $name, 'instance_name' => "55{$u->id}", 'status' => 'connected']);
        for ($i = 0; $i < 200; $i++) {
            WhatsappJob::create(['user_id' => $u->id, 'endpoint' => '5595', 'status' => 'pendente', 'payload' => []]);
        }

        return $u->fresh();
    }

    /** @return array<int,int> user_id => queued in this batch */
    private function queued(): array
    {
        $out = [];
        foreach (Queue::pushed(EnviarMensagemJob::class) as $p) {
            $ref = new \ReflectionProperty($p, 'jobModel');
            $ref->setAccessible(true);
            $uid = $ref->getValue($p)->user_id;
            $out[$uid] = ($out[$uid] ?? 0) + 1;
        }

        return $out;
    }

    public function test_plans_define_a_queue_weight_even_when_the_saved_plan_predates_the_limit(): void
    {
        $this->assertSame(1, Plan::where('slug', 'free')->first()->queueWeight());
        $this->assertSame(8, Plan::where('slug', 'business')->first()->queueWeight());

        $old = Plan::where('slug', 'pro')->first();
        $old->update(['limits' => ['instances' => 5]]); // saved before queue_priority existed
        $this->assertSame(4, $old->fresh()->queueWeight());
    }

    public function test_business_gets_a_bigger_share_of_the_batch_than_free(): void
    {
        $free = $this->account('free', 'free');
        $biz = $this->account('biz', 'business');

        Artisan::call('whatsapp:queue');

        $per = $this->queued();
        $this->assertSame(50, array_sum($per));
        $this->assertGreaterThan($per[$free->id], $per[$biz->id]);
        $this->assertEqualsWithDelta(44, $per[$biz->id], 2); // 8 / (8 + 1) of 50
        $this->assertGreaterThan(0, $per[$free->id], 'the free plan is slower, not starved');
    }

    public function test_strict_mode_serves_the_higher_plan_first(): void
    {
        config(['whatsapp_queue.priority.mode' => 'strict']);
        $free = $this->account('free', 'free');
        $biz = $this->account('biz', 'business');

        Artisan::call('whatsapp:queue');

        $per = $this->queued();
        $this->assertSame(50, $per[$biz->id]);
        $this->assertArrayNotHasKey($free->id, $per);
    }

    public function test_priority_can_be_turned_off_or_ignored_when_billing_is_off(): void
    {
        $free = $this->account('free', 'free');
        $biz = $this->account('biz', 'business');

        config(['whatsapp_queue.priority.enabled' => false]);
        Artisan::call('whatsapp:queue');
        $per = $this->queued();
        $this->assertSame(25, $per[$free->id]);
        $this->assertSame(25, $per[$biz->id]);
    }

    public function test_dry_run_shows_the_priority_column(): void
    {
        $this->account('biz', 'business');
        $this->artisan('whatsapp:queue', ['--dry-run' => true])->expectsOutputToContain('Priority')->assertSuccessful();
    }
}
