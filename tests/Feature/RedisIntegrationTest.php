<?php

namespace Tests\Feature;

use App\Jobs\ConfirmInstanceDownJob;
use App\Services\Instances\InstanceSendGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** Runs against a REAL Redis (REDIS_TEST_HOST/PORT, default 127.0.0.1:6379, databases 14/15). Skipped when none answers. */
class RedisIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $host = env('REDIS_TEST_HOST', '127.0.0.1');
        $port = (int) env('REDIS_TEST_PORT', 6379);
        $sock = @fsockopen($host, $port, $e, $s, 0.5);
        if (!$sock || !class_exists(\Predis\Client::class)) {
            $this->markTestSkipped('No Redis (or predis) available.');
        }
        fclose($sock);

        config([
            'database.redis.client' => 'predis',
            'database.redis.default' => ['host' => $host, 'port' => $port, 'password' => null, 'database' => 14],
            'database.redis.cache' => ['host' => $host, 'port' => $port, 'password' => null, 'database' => 14],
            'database.redis.queue' => ['host' => $host, 'port' => $port, 'password' => null, 'database' => 15],
            'cache.default' => 'redis',
            'cache.stores.redis.connection' => 'cache',
            'cache.stores.redis.lock_connection' => 'default',
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queue',
            'whatsapp_queue.pacing.enabled' => true,
            'whatsapp_queue.pacing.cooldown_min' => 30,
            'whatsapp_queue.pacing.cooldown_max' => 30,
        ]);
        app('redis')->purge();
        Redis::connection('default')->flushdb();
        Redis::connection('queue')->flushdb();
        Cache::forgetDriver('redis');
    }

    protected function tearDown(): void
    {
        if (class_exists(\Predis\Client::class) && config('queue.default') === 'redis') {
            Redis::connection('default')->flushdb();
            Redis::connection('queue')->flushdb();
        }
        parent::tearDown();
    }

    public function test_session_lock_is_atomic_across_separate_cache_instances(): void
    {
        $gate = app(InstanceSendGate::class);
        [$first, , $lock] = $gate->enter(7);
        $this->assertTrue($first);

        // a second worker = a brand-new cache manager talking to the same Redis
        Cache::forgetDriver('redis');
        [$second] = (new InstanceSendGate())->enter(7);
        $this->assertFalse($second);

        $gate->leave(7, $lock, true);
        $ttl = Redis::connection('cache')->ttl(config('cache.prefix') . ':whatsapp:session:7:next_send_at');
        $this->assertGreaterThan(0, $ttl);       // the pause expires by itself
        $this->assertFalse((new InstanceSendGate())->enter(7)[0]);
    }

    public function test_jobs_go_through_a_real_redis_queue_and_delayed_ones_wait(): void
    {
        Queue::connection('redis')->push(new ConfirmInstanceDownJob(1), '', 'default');
        ConfirmInstanceDownJob::dispatch(2)->delay(now()->addMinutes(3))->onQueue('default');

        $q = Redis::connection('queue');
        $this->assertSame(1, (int) $q->llen('queues:default'));
        $this->assertSame(1, (int) $q->zcard('queues:default:delayed'));

        $job = Queue::connection('redis')->pop('default');
        $this->assertNotNull($job);
        $this->assertSame(1, (int) $q->zcard('queues:default:reserved'));
        $job->delete();
        $this->assertSame(0, (int) $q->zcard('queues:default:reserved'));
        $this->assertNull(Queue::connection('redis')->pop('default'));   // the delayed one is not due yet
    }

    public function test_dispatch_lock_blocks_a_second_runner(): void
    {
        $a = Cache::lock('whatsapp:queue:dispatch', 60);
        $this->assertTrue($a->get());
        $this->assertFalse(Cache::lock('whatsapp:queue:dispatch', 60)->get());
        $a->release();
        $this->assertTrue(Cache::lock('whatsapp:queue:dispatch', 60)->get());
    }
}
