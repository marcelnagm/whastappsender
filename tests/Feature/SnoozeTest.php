<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\User;
use App\Services\Chat\Snooze;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class SnoozeTest extends TestCase
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

    private function chat(array $o = []): Chat
    {
        return Chat::forceCreate($o + ['user_id' => $this->owner->id, 'instance_id' => 1, 'remote_jid' => '5511999990000@s.whatsapp.net', 'phone' => '5511999990000', 'last_message_at' => now()]);
    }

    public function test_presets_resolve_to_future_times(): void
    {
        $svc = app(Snooze::class);
        $now = Carbon::parse('2026-11-08 19:00:00');   // a Sunday
        $this->assertSame('2026-11-08 20:00:00', $svc->until('1h', $now)->toDateTimeString());
        $this->assertSame('2026-11-09 09:00:00', $svc->until('tomorrow', $now)->toDateTimeString());
        $this->assertSame('2026-11-09 09:00:00', $svc->until('week', $now)->toDateTimeString());
        $this->assertNull($svc->until('bogus', $now));
    }

    public function test_run_wakes_only_expired_chats(): void
    {
        $due = $this->chat(['snoozed_until' => now()->subMinute()]);
        $later = $this->chat(['snoozed_until' => now()->addHour(), 'remote_jid' => 'x@s.whatsapp.net']);
        $this->assertSame(1, app(Snooze::class)->run());
        $this->assertNull($due->fresh()->snoozed_until);
        $this->assertNotNull($later->fresh()->snoozed_until);
        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_snoozed_chats_hidden_from_inbox_and_shown_in_own_filter(): void
    {
        $this->chat(['snoozed_until' => now()->addHour(), 'name' => 'Dormindo']);
        $this->chat(['remote_jid' => 'y@s.whatsapp.net', 'name' => 'Ativo']);
        $inst = \App\Models\Instance::forceCreate(['user_id' => $this->owner->id, 'name' => 'i', 'instance_name' => 'i', 'status' => 'open']);
        Chat::query()->update(['instance_id' => $inst->id]);

        $inbox = $this->actingAs($this->owner)->getJson(route('chat.api.chats', ['instance' => $inst->id]))->assertOk()->json('chats');
        $this->assertSame(['Ativo'], array_column($inbox, 'name'));
        $snoozed = $this->actingAs($this->owner)->getJson(route('chat.api.chats', ['instance' => $inst->id, 'filter' => 'snoozed']))->json('chats');
        $this->assertSame(['Dormindo'], array_column($snoozed, 'name'));
    }

    public function test_endpoints_and_other_accounts(): void
    {
        $chat = $this->chat();
        $this->actingAs($this->owner)->postJson(route('chat.api.snooze', $chat->id), ['preset' => '3h'])->assertOk();
        $this->assertTrue($chat->fresh()->snoozed_until->isFuture());
        $this->actingAs($this->owner)->postJson(route('chat.api.snooze', $chat->id), ['preset' => 'nope'])->assertStatus(422);
        $this->actingAs($this->owner)->deleteJson(route('chat.api.unsnooze', $chat->id))->assertOk();
        $this->assertNull($chat->fresh()->snoozed_until);

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->postJson(route('chat.api.snooze', $chat->id), ['preset' => '1h'])->assertStatus(403);
    }
}
