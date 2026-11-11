<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Instance;
use App\Models\User;
use App\Services\Chat\HumanRequests;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Dashboard\MissionControl;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class MissionControlTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Instance $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->inst = Instance::forceCreate(['user_id' => $this->owner->id, 'name' => 'Loja', 'instance_name' => 'loja', 'status' => 'connected']);
    }

    private function chat(array $o = []): Chat
    {
        static $n = 0;
        $n++;

        return Chat::forceCreate($o + ['user_id' => $this->owner->id, 'instance_id' => $this->inst->id, 'remote_jid' => "55119999{$n}@s.whatsapp.net", 'phone' => "55119999{$n}", 'name' => "Cliente $n",
            'last_message_at' => now()->subMinutes(30), 'last_message_from_me' => false, 'last_message_preview' => 'Oi']);
    }

    private function inbound(Chat $chat, string $body = 'Oi', int $minutesAgo = 30): ChatMessage
    {
        return ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $chat->instance_id, 'user_id' => $chat->user_id, 'from_me' => false, 'type' => 'text', 'body' => $body, 'message_timestamp' => now()->subMinutes($minutesAgo), 'status' => 'received']);
    }

    public function test_detects_requests_for_a_person_in_three_languages(): void
    {
        foreach (['Quero falar com um atendente', 'preciso de ATENDIMENTO HUMANO!', 'Can I talk to a human please', 'quiero hablar con una persona', 'Falar com uma pessoa'] as $t) {
            $this->assertTrue(HumanRequests::detect($t), $t);
        }
        foreach (['Qual o preço?', 'Vocês têm atendimento aos sábados?', 'the human body is amazing', ''] as $t) {
            $this->assertFalse(HumanRequests::detect($t), $t);
        }
    }

    public function test_request_flags_once_and_notifies(): void
    {
        $chat = $this->chat();
        $svc = app(HumanRequests::class);
        $svc->request($chat, 'Pediu atendente');
        $svc->request($chat->fresh(), 'de novo');
        $this->assertNotNull($chat->fresh()->needs_human_at);
        $this->assertSame('Pediu atendente', $chat->fresh()->needs_human_reason);
        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_a_person_answering_clears_pending_and_human_but_a_bot_does_not(): void
    {
        $chat = $this->chat(['pending_at' => now(), 'needs_human_at' => now()]);
        $rec = app(ChatMessageRecorder::class);

        $bot = ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $chat->instance_id, 'user_id' => $chat->user_id, 'from_me' => true, 'source' => 'ai', 'type' => 'text', 'body' => 'resposta da IA', 'message_timestamp' => now()]);
        $rec->touchChat($chat->fresh(), $bot, true);
        $this->assertNotNull($chat->fresh()->pending_at);
        $this->assertNotNull($chat->fresh()->needs_human_at);

        $human = ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $chat->instance_id, 'user_id' => $chat->user_id, 'from_me' => true, 'type' => 'text', 'body' => 'Oi, sou a Ana', 'message_timestamp' => now()->addSecond()]);
        $rec->touchChat($chat->fresh(), $human, true);
        $this->assertNull($chat->fresh()->pending_at);
        $this->assertNull($chat->fresh()->needs_human_at);
    }

    public function test_pending_endpoints_and_filters(): void
    {
        $a = $this->chat(['name' => 'Pendente']);
        $b = $this->chat(['name' => 'Normal']);
        $this->actingAs($this->owner)->postJson(route('chat.api.pending', $a))->assertOk()->assertJson(['pending' => true]);
        $this->assertNotNull($a->fresh()->pending_at);

        $list = $this->getJson(route('chat.api.chats', ['instance' => $this->inst->id, 'filter' => 'pending']))->assertOk()->json('chats');
        $this->assertSame(['Pendente'], array_column($list, 'name'));
        $this->assertTrue($list[0]['pending']);

        $this->getJson(route('chat.api.crm', $a))->assertOk()->assertJson(['pending' => true]);
        $this->deleteJson(route('chat.api.unpending', $a))->assertOk();
        $this->assertNull($a->fresh()->pending_at);

        $b->forceFill(['needs_human_at' => now(), 'needs_human_reason' => 'x'])->save();
        $human = $this->getJson(route('chat.api.chats', ['instance' => $this->inst->id, 'filter' => 'human']))->json('chats');
        $this->assertSame(['Normal'], array_column($human, 'name'));
        $this->deleteJson(route('chat.api.human.dismiss', $b))->assertOk();
        $this->assertNull($b->fresh()->needs_human_at);
    }

    public function test_closing_a_conversation_clears_the_flags(): void
    {
        $chat = $this->chat(['pending_at' => now(), 'needs_human_at' => now()]);
        $this->actingAs($this->owner)->postJson(route('chat.api.close', $chat))->assertOk();
        $this->assertNull($chat->fresh()->pending_at);
        $this->assertNull($chat->fresh()->needs_human_at);
    }

    public function test_inbound_hook_flags_a_customer_asking_for_a_person(): void
    {
        $chat = $this->chat();
        $m = $this->inbound($chat, 'Quero falar com um atendente');
        $this->assertTrue(HumanRequests::detect($m->body));
        app(HumanRequests::class)->request($chat, __('The customer asked for a person.'));
        $this->assertNotNull($chat->fresh()->needs_human_at);
    }

    public function test_dashboard_puts_what_needs_a_person_first(): void
    {
        $quiet = $this->chat(['name' => 'Aguardando', 'last_message_at' => now()->subHours(3)]);
        $this->inbound($quiet, 'Oi', 180);
        $human = $this->chat(['name' => 'Quer pessoa', 'needs_human_at' => now(), 'needs_human_reason' => 'Pediu atendente', 'last_message_at' => now()->subMinutes(2)]);
        $this->inbound($human);
        $pending = $this->chat(['name' => 'Pendente', 'pending_at' => now()->subHour(), 'last_message_from_me' => true]);
        $this->chat(['name' => 'Respondido', 'last_message_from_me' => true]);
        $this->chat(['name' => 'Encerrado', 'closed_at' => now()]);
        $this->chat(['name' => 'Adiado', 'snoozed_until' => now()->addDay()]);

        $mc = app(MissionControl::class)->build($this->owner, null, ['chat' => true, 'deals' => true, 'tasks' => true]);
        $names = array_column($mc['queue'], 'name');
        $this->assertSame('Quer pessoa', $names[0]);
        $this->assertContains('Aguardando', $names);
        $this->assertContains('Pendente', $names);
        $this->assertNotContains('Respondido', $names);
        $this->assertNotContains('Encerrado', $names);
        $this->assertNotContains('Adiado', $names);
        $this->assertSame(1, $mc['counts']['human']);
        $this->assertSame(1, $mc['counts']['pending']);
        $this->assertSame(2, $mc['counts']['waiting']);     // Aguardando + Quer pessoa
        $this->assertTrue($mc['queue'][array_search('Aguardando', $names)]['late']);
        $this->assertGreaterThanOrEqual(179, $mc['queue'][array_search('Aguardando', $names)]['waited']);
    }

    public function test_dashboard_page_renders_with_alerts_and_channels(): void
    {
        $down = Instance::forceCreate(['user_id' => $this->owner->id, 'name' => 'Filial', 'instance_name' => 'filial', 'status' => 'close']);
        $c = $this->chat(['name' => 'Quer pessoa', 'needs_human_at' => now(), 'needs_human_reason' => 'Pediu atendente']);
        $this->inbound($c);

        $r = $this->actingAs($this->owner)->get(route('home.index'))->assertOk();
        $r->assertSee('Mission control');
        $r->assertSee('Quer pessoa');
        $r->assertSee('Pediu atendente');
        $r->assertSee('Filial');                                      // disconnected channel alert
        $r->assertSee(e(route('chat.index', ['instance' => $this->inst->id, 'chat' => $c->id])), false);
        $r->assertDontSee('Total mined');
    }

    public function test_agents_restricted_to_their_chats_do_not_see_others(): void
    {
        $agent = User::create(['name' => 'Ag', 'email' => 'a@t.dev', 'username' => 'ag', 'password' => 'x'])->fresh();
        $mine = $this->chat(['name' => 'Meu', 'assigned_to' => $agent->id]);
        $other = $this->chat(['name' => 'Do outro', 'assigned_to' => $this->owner->id]);
        $free = $this->chat(['name' => 'Livre']);
        foreach ([$mine, $other, $free] as $c) {
            $this->inbound($c);
        }
        $names = array_column(app(MissionControl::class)->build($this->owner, $agent->id, ['chat' => true])['queue'], 'name');
        $this->assertEqualsCanonicalizing(['Meu', 'Livre'], $names);
    }
}
