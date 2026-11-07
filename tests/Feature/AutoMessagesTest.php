<?php

namespace Tests\Feature;

use App\Jobs\SendAiReplyJob;
use App\Models\AiMessage;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\ServiceHours;
use App\Models\User;
use App\Services\Chat\AutoMessages;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Opening hours, away message and greeting. */
class AutoMessagesTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Instance $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Queue::fake();
        $this->owner = User::create(['name' => 'Loja Sol', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hours(array $o = []): ServiceHours
    {
        return ServiceHours::create($o + ['user_id' => $this->owner->id, 'timezone' => 'UTC', 'business_days' => '1,2,3,4,5', 'business_start' => '09:00', 'business_end' => '18:00',
            'away_enabled' => true, 'away_message' => 'Oi {{first_name}}, abrimos {{hours}}.', 'away_every_hours' => 8, 'greeting_enabled' => true, 'greeting_message' => 'Bem-vindo à {{company}}!']);
    }

    private function chat(string $phone = '1', array $contact = []): Chat
    {
        $c = Contact::create($contact + ['user_id' => $this->owner->id, 'name' => 'Ana Souza', 'contact' => $phone]);

        return Chat::create(['user_id' => $this->owner->id, 'instance_id' => $this->inst->id, 'contact_id' => $c->id, 'remote_jid' => $phone . '@s.whatsapp.net']);
    }

    private function inbound(Chat $chat, string $id = 'A'): ChatMessage
    {
        return ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $this->inst->id, 'user_id' => $this->owner->id, 'message_id' => $id . uniqid(), 'from_me' => false, 'type' => 'text', 'body' => 'oi', 'message_timestamp' => now()]);
    }

    private function clock(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when, 'UTC')); // 2026-11-06 is a Friday, 2026-11-07 a Saturday
    }

    public function test_away_message_outside_hours_once_per_window(): void
    {
        $this->hours();
        $this->clock('2026-11-07 10:00:00'); // Saturday
        $chat = $this->chat();

        $this->inbound($chat);
        $this->inbound($chat);

        $this->assertSame(1, AiMessage::where('provider', 'auto')->count());
        $this->assertSame('Oi Ana, abrimos 09:00–18:00.', AiMessage::where('provider', 'auto')->value('content'));
        Queue::assertPushed(SendAiReplyJob::class, 1);

        $this->clock('2026-11-07 19:00:00'); // 9h later: again
        $this->inbound($chat);
        $this->assertSame(2, AiMessage::where('provider', 'auto')->count());
    }

    public function test_greeting_only_on_the_first_message_inside_hours(): void
    {
        $this->hours();
        $this->clock('2026-11-06 10:00:00'); // Friday, open
        $chat = $this->chat();

        $this->inbound($chat);
        $this->inbound($chat);

        $msgs = AiMessage::where('provider', 'auto')->get();
        $this->assertCount(1, $msgs);
        $this->assertSame('greeting', $msgs[0]->model);
        $this->assertSame('Bem-vindo à Loja Sol!', $msgs[0]->content);
        $this->assertNotNull($chat->fresh()->greeted_at);
    }

    public function test_nothing_when_disabled_or_not_configured(): void
    {
        $this->clock('2026-11-07 10:00:00');
        $this->inbound($this->chat('1')); // no settings at all
        $this->hours(['away_enabled' => false, 'greeting_enabled' => false]);
        $this->inbound($this->chat('2'));

        $this->assertSame(0, AiMessage::count());
    }

    public function test_skipped_for_opted_out_contacts_groups_and_own_messages(): void
    {
        $this->hours();
        $this->clock('2026-11-07 10:00:00');

        $this->inbound($this->chat('1', ['consent_status' => 'revoked']));
        $g = $this->chat('2');
        $g->update(['remote_jid' => '123@g.us']);
        $this->inbound($g);
        $c = $this->chat('3');
        ChatMessage::create(['chat_id' => $c->id, 'instance_id' => $this->inst->id, 'user_id' => $this->owner->id, 'message_id' => 'own', 'from_me' => true, 'type' => 'text', 'body' => 'x', 'message_timestamp' => now()]);

        $this->assertSame(0, AiMessage::count());
    }

    public function test_agent_covering_the_hours_suppresses_away_and_greeting(): void
    {
        $this->hours();
        $this->owner->forceFill(['ai_enabled' => true, 'ai_mode' => 'auto', 'ai_model' => 'm'])->save();
        \App\Models\AiProfile::create(['user_id' => $this->owner->id, 'name' => 'A', 'enabled' => true, 'provider' => 'groq', 'model' => 'm', 'temperature' => 0.5, 'max_tokens' => 200, 'business_hours_only' => false]);

        $this->clock('2026-11-07 10:00:00');
        $this->inbound($this->chat('1')); // closed, but the agent answers anyway
        $this->clock('2026-11-06 10:00:00');
        $this->inbound($this->chat('2')); // open, the agent greets by itself

        $this->assertSame(0, AiMessage::where('provider', 'auto')->count());
    }

    public function test_unknown_variables_are_left_and_phone_numbers_are_not_used_as_names(): void
    {
        $cfg = $this->hours();
        $chat = $this->chat('5511', ['name' => '+5511']);

        $this->assertSame('Oi , {{nope}} Loja Sol', app(AutoMessages::class)->render('Oi {{first_name}}, {{nope}} {{company}}', $chat, $this->owner, $cfg));
    }

    public function test_settings_page_and_validation(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('hours.edit'))->assertOk()->assertSee('Opening hours');

        $this->put(route('hours.update'), ['timezone' => 'Mars/Base', 'business_start' => '09:00', 'business_end' => '18:00'])->assertSessionHasErrors('timezone');
        $this->put(route('hours.update'), ['timezone' => 'UTC', 'business_start' => '09:00', 'business_end' => '18:00', 'away_enabled' => '1', 'away_message' => ''])->assertSessionHasErrors('away_message');
        $this->put(route('hours.update'), ['timezone' => 'America/Boa_Vista', 'business_start' => '08:00', 'business_end' => '17:00', 'days' => [1, 2, 6], 'away_enabled' => '1', 'away_message' => 'Fechado', 'away_every_hours' => 4])->assertSessionHasNoErrors();

        $c = ServiceHours::firstOrFail();
        $this->assertSame('1,2,6', $c->business_days);
        $this->assertTrue($c->away_enabled);
        $this->assertFalse($c->greeting_enabled);
        $this->assertSame(4, $c->away_every_hours);
    }

    public function test_permissions(): void
    {
        $agent = User::create(['name' => 'Bia', 'email' => 'b@t.dev', 'username' => 'b', 'password' => 'x']);
        $agent->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT, 'active' => true])->save();

        $this->actingAs($agent)->get(route('hours.edit'))->assertForbidden();
    }
}
