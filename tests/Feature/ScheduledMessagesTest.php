<?php

namespace Tests\Feature;

use App\Jobs\SendChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Services\Chat\ScheduledMessages;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Messages typed now and sent later. */
class ScheduledMessagesTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5595999990000']);
        $this->chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'contact_id' => $c->id, 'remote_jid' => '5595999990000@s.whatsapp.net']);
    }

    private function due(array $o = []): ScheduledMessage
    {
        $m = ScheduledMessage::create($o + ['user_id' => $this->owner->id, 'chat_id' => $this->chat->id, 'created_by' => $this->owner->id, 'body' => 'Oi, tudo bem?',
            'send_at' => now()->subMinute(), 'cancel_on_reply' => true, 'status' => 'pending']);
        ScheduledMessage::whereKey($m->id)->update(['created_at' => now()->subHour()]);

        return $m->fresh();
    }

    public function test_due_message_is_sent_in_the_name_of_who_scheduled_it(): void
    {
        Queue::fake();
        $m = $this->due();

        $this->assertSame(1, app(ScheduledMessages::class)->dispatchDue());

        $msg = ChatMessage::firstOrFail();
        $this->assertSame('Oi, tudo bem?', $msg->body);
        $this->assertTrue((bool) $msg->from_me);
        $this->assertSame($this->owner->id, (int) $msg->sent_by);
        $this->assertSame('human', $msg->source);
        $this->assertSame('sent', $m->fresh()->status);
        $this->assertSame($msg->id, (int) $m->fresh()->message_id);
        Queue::assertPushed(SendChatMessageJob::class);

        $this->assertSame(0, app(ScheduledMessages::class)->dispatchDue()); // never twice
    }

    public function test_future_messages_wait(): void
    {
        Queue::fake();
        $this->due(['send_at' => now()->addHour()]);

        $this->assertSame(0, app(ScheduledMessages::class)->dispatchDue());
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_customer_reply_cancels_when_asked_and_not_otherwise(): void
    {
        Queue::fake();
        ChatMessage::create(['chat_id' => $this->chat->id, 'instance_id' => $this->chat->instance_id, 'user_id' => $this->owner->id, 'message_id' => 'in1', 'from_me' => false,
            'type' => 'text', 'body' => 'Oi!', 'message_timestamp' => now()->subMinutes(10)]);
        $a = $this->due();
        $b = $this->due(['cancel_on_reply' => false, 'body' => 'Segunda']);

        app(ScheduledMessages::class)->dispatchDue();

        $this->assertSame('canceled', $a->fresh()->status);
        $this->assertSame('sent', $b->fresh()->status);
        $this->assertSame(1, ChatMessage::where('from_me', true)->count());
    }

    public function test_closed_reply_window_fails_with_a_reason(): void
    {
        Queue::fake();
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'ig', 'instance_name' => 'ig', 'status' => 'connected', 'channel' => 'instagram']);
        $chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'contact_id' => $this->chat->contact_id, 'remote_jid' => 'ig:1']);
        $m = $this->due(['chat_id' => $chat->id]);

        app(ScheduledMessages::class)->dispatchDue();

        $this->assertSame('failed', $m->fresh()->status);
        $this->assertNotEmpty($m->fresh()->note);
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_deleted_conversation_fails_softly(): void
    {
        $m = $this->due(['chat_id' => 9999]);

        app(ScheduledMessages::class)->dispatchDue();

        $this->assertSame('failed', $m->fresh()->status);
    }

    public function test_command_runs_the_dispatcher(): void
    {
        Queue::fake();
        $this->due();

        $this->artisan('chat:send-scheduled')->assertExitCode(0);

        $this->assertSame(1, ChatMessage::count());
    }

    public function test_api_validates_lists_and_cancels(): void
    {
        $this->actingAs($this->owner);
        $url = route('chat.api.scheduled.store', $this->chat->id);

        $this->postJson($url, ['body' => 'x', 'send_at' => now()->addSeconds(10)->format('Y-m-d\TH:i')])->assertStatus(422);        // too soon
        $this->postJson($url, ['body' => 'x', 'send_at' => now()->addDays(120)->format('Y-m-d\TH:i')])->assertStatus(422);          // too far
        $this->postJson($url, ['body' => '   ', 'send_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertStatus(422);
        $r = $this->postJson($url, ['body' => 'Lembrete', 'send_at' => now()->addDay()->format('Y-m-d\TH:i'), 'cancel_on_reply' => false])->assertStatus(201);

        $r->assertJsonPath('scheduled.0.body', 'Lembrete');
        $this->assertFalse(ScheduledMessage::first()->cancel_on_reply);
        $this->getJson(route('chat.api.crm', $this->chat->id))->assertJsonPath('scheduled.0.body', 'Lembrete');

        $id = ScheduledMessage::first()->id;
        $this->deleteJson(route('chat.api.scheduled.destroy', [$this->chat->id, $id]))->assertOk()->assertJsonPath('scheduled', []);
        $this->assertSame('canceled', ScheduledMessage::first()->status);
    }

    public function test_pending_limit(): void
    {
        $this->actingAs($this->owner);
        for ($i = 0; $i < ScheduledMessages::MAX_PENDING; $i++) {
            ScheduledMessage::create(['user_id' => $this->owner->id, 'chat_id' => $this->chat->id, 'body' => 'x', 'send_at' => now()->addDay(), 'status' => 'pending']);
        }

        $this->postJson(route('chat.api.scheduled.store', $this->chat->id), ['body' => 'x', 'send_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertStatus(422);
    }

    public function test_other_accounts_and_restricted_agents_are_blocked(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->postJson(route('chat.api.scheduled.store', $this->chat->id), ['body' => 'x', 'send_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertForbidden();

        $m = $this->due(['send_at' => now()->addDay()]);
        $this->actingAs($other)->deleteJson(route('chat.api.scheduled.destroy', [$this->chat->id, $m->id]))->assertForbidden();

        $this->owner->forceFill(['chat_agents_see' => 'assigned'])->save();
        $agent = User::create(['name' => 'Bia', 'email' => 'b@t.dev', 'username' => 'b', 'password' => 'x']);
        $agent->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT, 'active' => true])->save();
        $teammate = User::create(['name' => 'Caio', 'email' => 'c@t.dev', 'username' => 'c', 'password' => 'x']);
        $teammate->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT, 'active' => true])->save();
        $this->chat->forceFill(['assigned_to' => $teammate->id])->save();

        $this->actingAs($agent)->postJson(route('chat.api.scheduled.store', $this->chat->id), ['body' => 'x', 'send_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertForbidden();
    }
}
