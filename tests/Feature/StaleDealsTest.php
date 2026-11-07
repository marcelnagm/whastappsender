<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Deal;
use App\Models\Instance;
use App\Models\StaleDealSetting;
use App\Models\User;
use App\Services\Pipeline\StaleDeals;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class StaleDealsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    private function deal(int $idleDays, array $o = []): Deal
    {
        $d = Deal::forceCreate($o + ['user_id' => $this->owner->id, 'title' => 'Negócio', 'value' => 10, 'status' => 'open', 'pipeline_id' => 1, 'stage_id' => 1]);
        Deal::whereKey($d->id)->update(['updated_at' => now()->subDays($idleDays)]);

        return $d->fresh();
    }

    public function test_only_idle_open_deals_are_stale_and_notified_once(): void
    {
        StaleDealSetting::create(['user_id' => $this->owner->id, 'enabled' => true, 'days' => 7]);
        $old = $this->deal(10);
        $this->deal(2);
        $this->deal(30, ['status' => 'won']);
        $svc = app(StaleDeals::class);

        $this->assertSame([$old->id], $svc->stale($this->owner->id, 7)->pluck('deal.id')->all());
        $this->assertSame(1, $svc->run());
        $this->assertSame(0, $svc->run());
        $this->assertSame(1, $this->owner->notifications()->count());

        // a new sign of life and a new stall notify again
        Deal::whereKey($old->id)->update(['updated_at' => now()->subDays(9)]);
        $this->assertSame(0, $svc->run());           // still before the previous notice
        Deal::whereKey($old->id)->toBase()->update(['stale_notified_at' => now()->subDays(20)]);
        $this->assertSame(1, $svc->run());
    }

    public function test_recent_conversation_keeps_the_deal_alive(): void
    {
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'remote_jid' => 'x@s.whatsapp.net', 'last_message_at' => now()->subDay()]);
        $this->deal(20, ['chat_id' => $chat->id]);
        $this->assertCount(0, app(StaleDeals::class)->stale($this->owner->id, 7));
    }

    public function test_disabled_does_nothing_and_page_works(): void
    {
        $this->deal(30);
        $this->assertSame(0, app(StaleDeals::class)->run());
        $this->actingAs($this->owner)->get(route('pipeline.stale'))->assertOk()->assertSee('Negócio');
        $this->actingAs($this->owner)->put(route('pipeline.stale.update'), ['enabled' => 1, 'days' => 5])->assertRedirect();
        $this->assertSame(5, StaleDealSetting::for($this->owner->id)->days);
        $this->assertSame(1, app(StaleDeals::class)->run());
    }
}
