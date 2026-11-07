<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\ServiceHours;
use App\Models\SlaEvent;
use App\Models\SlaSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** First-response SLA: alert, escalation and idempotency. */
class SlaTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $boss;
    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->boss = User::create(['name' => 'Chefe', 'email' => 'b@t.dev', 'username' => 'b', 'password' => 'x'])->fresh();
        $this->boss->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => \App\Services\Team\TeamRoles::AGENT, 'active' => true])->save();
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5595999990000']);
        $this->chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'contact_id' => $c->id, 'remote_jid' => '5595999990000@s.whatsapp.net', 'name' => 'Ana']);
    }

    private function msg(bool $fromMe, Carbon $at, array $o = []): ChatMessage
    {
        $m = ChatMessage::create($o + ['chat_id' => $this->chat->id, 'instance_id' => $this->chat->instance_id, 'user_id' => $this->owner->id, 'message_id' => uniqid(), 'from_me' => $fromMe,
            'type' => 'text', 'body' => 'x', 'message_timestamp' => $at]);
        $this->chat->forceFill(['last_message_from_me' => $fromMe, 'last_message_at' => $at])->save();

        return $m;
    }

    private function sla(array $o = []): SlaSetting
    {
        return SlaSetting::create($o + ['user_id' => $this->owner->id, 'enabled' => true, 'first_response_minutes' => 15, 'escalate_after_minutes' => 30, 'business_only' => false, 'escalate_to' => $this->boss->id]);
    }

    public function test_breach_then_escalation_once_each(): void
    {
        $this->sla();
        $this->msg(false, Carbon::parse('2026-11-09 10:00:00'));
        $mon = app(\App\Services\Sla\SlaMonitor::class);

        $this->assertSame(['breaches' => 0, 'escalations' => 0], $mon->run(Carbon::parse('2026-11-09 10:10:00')));
        $this->assertSame(['breaches' => 1, 'escalations' => 0], $mon->run(Carbon::parse('2026-11-09 10:16:00')));
        $this->assertSame(['breaches' => 0, 'escalations' => 0], $mon->run(Carbon::parse('2026-11-09 10:20:00')));
        $this->assertSame(['breaches' => 0, 'escalations' => 1], $mon->run(Carbon::parse('2026-11-09 10:46:00')));
        $this->assertSame(['breaches' => 0, 'escalations' => 0], $mon->run(Carbon::parse('2026-11-09 11:30:00')));
        $this->assertSame(1, $this->boss->notifications()->count());
        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_answer_stops_the_clock_and_resolves(): void
    {
        $this->sla();
        $this->msg(false, Carbon::parse('2026-11-09 10:00:00'));
        $mon = app(\App\Services\Sla\SlaMonitor::class);
        $mon->run(Carbon::parse('2026-11-09 10:20:00'));
        $this->msg(true, Carbon::parse('2026-11-09 10:25:00'), ['source' => 'human']);

        $this->assertSame(['breaches' => 0, 'escalations' => 0], $mon->run(Carbon::parse('2026-11-09 11:30:00')));
        $this->assertNotNull(SlaEvent::first()->resolved_at);
    }

    public function test_campaign_message_is_not_an_answer(): void
    {
        $this->sla();
        $this->msg(false, Carbon::parse('2026-11-09 10:00:00'));
        $this->msg(true, Carbon::parse('2026-11-09 10:05:00'), ['source' => 'campaign']);
        $this->chat->forceFill(['last_message_from_me' => false])->save();

        $this->assertSame(1, app(\App\Services\Sla\SlaMonitor::class)->run(Carbon::parse('2026-11-09 10:20:00'))['breaches']);
    }

    public function test_disabled_sla_does_nothing(): void
    {
        $this->sla(['enabled' => false]);
        $this->msg(false, Carbon::parse('2026-11-09 10:00:00'));

        $this->assertSame(0, app(\App\Services\Sla\SlaMonitor::class)->run(Carbon::parse('2026-11-09 18:00:00'))['breaches']);
    }

    public function test_reassigns_on_escalation_when_asked(): void
    {
        $this->sla(['reassign' => true]);
        $this->msg(false, Carbon::parse('2026-11-09 10:00:00'));
        app(\App\Services\Sla\SlaMonitor::class)->run(Carbon::parse('2026-11-09 10:50:00'));

        $this->assertSame($this->boss->id, $this->chat->fresh()->assigned_to);
    }

    public function test_business_only_ignores_closed_hours(): void
    {
        ServiceHours::create(['user_id' => $this->owner->id, 'timezone' => 'UTC', 'business_days' => '1,2,3,4,5', 'business_start' => '09:00', 'business_end' => '18:00', 'away_every_hours' => 8]);
        $cfg = $this->sla(['business_only' => true]);
        $mon = app(\App\Services\Sla\SlaMonitor::class);

        // Friday 17:50 -> Monday 09:20 = 10 + 20 business minutes
        $this->assertSame(30, $mon->waitedMinutes($cfg, Carbon::parse('2026-11-06 17:50:00', 'UTC'), Carbon::parse('2026-11-09 09:20:00', 'UTC')));
        // all night and weekend, nothing counted
        $this->assertSame(0, $mon->waitedMinutes($cfg, Carbon::parse('2026-11-06 19:00:00', 'UTC'), Carbon::parse('2026-11-09 08:00:00', 'UTC')));
    }

    public function test_page_and_settings(): void
    {
        $this->actingAs($this->owner)->get(route('sla.index'))->assertOk();
        $this->actingAs($this->owner)->put(route('sla.update'), ['enabled' => 1, 'first_response_minutes' => 10, 'escalate_after_minutes' => 20, 'escalate_to' => $this->boss->id, 'business_only' => 1])->assertRedirect();
        $this->assertSame(10, SlaSetting::for($this->owner->id)->first_response_minutes);
        $this->actingAs($this->owner)->put(route('sla.update'), ['first_response_minutes' => 10, 'escalate_after_minutes' => 20, 'escalate_to' => 999])->assertSessionHasErrors('escalate_to');
    }

    public function test_agents_cannot_open_the_page(): void
    {
        $agent = User::create(['name' => 'Ag', 'email' => 'a@t.dev', 'username' => 'a', 'password' => 'x'])->fresh();
        $agent->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => \App\Services\Team\TeamRoles::AGENT, 'active' => true])->save();
        $this->actingAs($agent)->get(route('sla.index'))->assertForbidden();
    }
}
