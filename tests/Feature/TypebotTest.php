<?php

namespace Tests\Feature;

use App\Jobs\SendChatMessageJob;
use App\Models\AiSession;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatNote;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\TypebotSession;
use App\Models\TypebotSetting;
use App\Models\User;
use App\Services\Bots\TypebotBot;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class TypebotTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Chat $chat;
    private Instance $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        Queue::fake([SendChatMessageJob::class]);
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana Souza', 'contact' => '5595999990000']);
        $this->chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $this->inst->id, 'contact_id' => $c->id, 'remote_jid' => '5595999990000@s.whatsapp.net', 'name' => 'Ana']);
        TypebotSetting::create(['user_id' => $this->owner->id, 'enabled' => true, 'base_url' => 'https://bot.example.com', 'public_id' => 'flow-1', 'handoff_keywords' => 'atendente, falar com humano', 'on_finish' => 'none', 'expire_minutes' => 60]);
    }

    private function in(string $body): ChatMessage
    {
        return ChatMessage::create(['chat_id' => $this->chat->id, 'instance_id' => $this->inst->id, 'user_id' => $this->owner->id, 'message_id' => uniqid(), 'from_me' => false, 'type' => 'text', 'body' => $body, 'message_timestamp' => now()]);
    }

    private function bot(): array
    {
        return ChatMessage::where('source', 'bot')->orderBy('id')->pluck('body')->all();
    }

    private function text(string $md): array
    {
        return ['id' => uniqid(), 'type' => 'text', 'content' => ['type' => 'markdown', 'markdown' => $md]];
    }

    public function test_first_message_starts_the_flow_and_renders_choices(): void
    {
        Http::fake(['bot.example.com/api/v1/typebots/flow-1/startChat' => Http::response([
            'sessionId' => 's1',
            'messages' => [$this->text('Olá **Ana**!'), ['id' => 'i', 'type' => 'image', 'content' => ['url' => 'https://x.io/a.png']]],
            'input' => ['type' => 'choice input', 'items' => [['id' => 'a', 'content' => 'Vendas'], ['id' => 'b', 'content' => 'Suporte']]],
        ])]);

        $this->in('oi');

        $this->assertSame(['Olá *Ana*!', 'https://x.io/a.png', "1) Vendas\n2) Suporte"], $this->bot());
        $s = TypebotSession::first();
        $this->assertSame('active', $s->status);
        $this->assertSame('s1', $s->session_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'startChat') && $r['prefilledVariables']['first_name'] === 'Ana' && str_contains($r['prefilledVariables']['handoff_url'], '/api/public/typebot/handoff/' . $this->chat->id . '/'));
        $this->assertNull($this->chat->fresh()->assigned_to);
    }

    public function test_numbered_answer_is_translated_and_flow_finishes(): void
    {
        Http::fake([
            '*startChat' => Http::response(['sessionId' => 's1', 'messages' => [$this->text('Qual setor?')], 'input' => ['type' => 'choice input', 'items' => [['content' => 'Vendas'], ['content' => 'Suporte']]]]),
            '*continueChat' => Http::response(['messages' => [$this->text('Obrigado!')]]),
        ]);
        $this->in('oi');
        $this->in('2');

        Http::assertSent(fn ($r) => str_contains($r->url(), 's1/continueChat') && $r['message']['text'] === 'Suporte');
        $this->assertSame('finished', TypebotSession::first()->status);
        $this->assertContains('Obrigado!', $this->bot());
    }

    public function test_keyword_hands_over_pauses_ai_notes_and_notifies(): void
    {
        Http::fake(['*startChat' => Http::response(['sessionId' => 's1', 'messages' => [$this->text('Olá')], 'input' => ['type' => 'text input']])]);
        $this->in('oi');
        $this->in('Quero falar com humano por favor');

        $this->assertSame('handoff', TypebotSession::first()->status);
        $this->assertTrue((bool) AiSession::first()->human_handoff);
        $this->assertStringContainsString('Bot handoff', ChatNote::first()->body);
        $this->assertSame(1, $this->owner->notifications()->count());
        Http::assertSentCount(1);                       // the keyword never reached the flow
        $this->in('alguém?');
        Http::assertSentCount(1);                       // and the bot stays out afterwards
    }

    public function test_flow_calls_the_signed_handoff_address(): void
    {
        Http::fake(['*startChat' => Http::response(['sessionId' => 's1', 'messages' => [$this->text('Olá')], 'input' => ['type' => 'text input']])]);
        $this->in('oi');
        $url = TypebotBot::handoffUrl($this->chat);

        $this->postJson(str_replace(url('/'), '/', $url), ['note' => 'quer orçamento'])->assertOk();
        $this->assertSame('handoff', TypebotSession::first()->status);
        $this->assertStringContainsString('quer orçamento', ChatNote::first()->body);
        $this->postJson(str_replace(url('/'), '/', $url))->assertOk();
        $this->assertSame(1, ChatNote::count());          // never twice

        $bad = '/api/public/typebot/handoff/' . $this->chat->id . '/' . str_repeat('a', 32);
        $this->postJson($bad)->assertForbidden();
    }

    public function test_human_reply_and_assignment_stop_the_bot(): void
    {
        Http::fake(['*startChat' => Http::response(['sessionId' => 's1', 'messages' => [$this->text('Olá')], 'input' => ['type' => 'text input']])]);
        $this->in('oi');
        ChatMessage::create(['chat_id' => $this->chat->id, 'instance_id' => $this->inst->id, 'user_id' => $this->owner->id, 'from_me' => true, 'source' => 'human', 'type' => 'text', 'body' => 'Oi, sou a Maria', 'message_timestamp' => now()]);
        $this->assertSame('handoff', TypebotSession::first()->status);

        $this->in('obrigada');
        Http::assertSentCount(1);
    }

    public function test_assigned_conversation_is_not_taken_by_the_bot_and_errors_fall_back_to_a_person(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->in('oi');
        $this->assertSame('handoff', TypebotSession::first()->status);          // error -> person
        $this->assertSame(1, $this->owner->notifications()->count());

        TypebotSession::query()->delete();
        $this->chat->forceFill(['assigned_to' => $this->owner->id])->save();
        $before = count(Http::recorded());
        $this->in('de novo');
        $this->assertSame($before, count(Http::recorded()));
    }

    public function test_expired_session_starts_over_and_disabled_does_nothing(): void
    {
        Http::fake(['*startChat' => Http::response(['sessionId' => 'new', 'messages' => [$this->text('De novo')], 'input' => ['type' => 'text input']])]);
        TypebotSession::create(['user_id' => $this->owner->id, 'chat_id' => $this->chat->id, 'session_id' => 'old', 'status' => 'active', 'last_activity_at' => now()->subHours(3)]);
        $this->in('oi');
        $this->assertSame('new', TypebotSession::first()->session_id);

        TypebotSetting::where('user_id', $this->owner->id)->update(['enabled' => false]);
        TypebotSession::query()->delete();
        $this->in('oi 2');
        Http::assertSentCount(1);
    }

    public function test_settings_page_validation_and_connection_test(): void
    {
        $this->actingAs($this->owner)->get(route('typebot.index'))->assertOk()->assertSee('handoff_url');
        $this->actingAs($this->owner)->put(route('typebot.update'), ['enabled' => 1, 'base_url' => 'https://127.0.0.1', 'public_id' => 'x', 'on_finish' => 'none', 'expire_minutes' => 60])->assertSessionHasErrors('base_url');
        $this->actingAs($this->owner)->put(route('typebot.update'), ['enabled' => 1, 'base_url' => 'https://93.184.216.34/', 'public_id' => 'my flow', 'on_finish' => 'none', 'expire_minutes' => 60])->assertSessionHasErrors('public_id');
        $this->actingAs($this->owner)->put(route('typebot.update'), ['enabled' => 1, 'base_url' => 'https://93.184.216.34/', 'public_id' => 'my-flow', 'on_finish' => 'handoff', 'expire_minutes' => 30])->assertRedirect();
        $this->assertSame('https://93.184.216.34', TypebotSetting::first()->base_url);

        Http::fake(['*startChat' => Http::response(['sessionId' => 'x'])]);
        $this->actingAs($this->owner)->post(route('typebot.test'))->assertSessionHas('success');
        Http::assertSent(fn ($r) => $r['isOnlyRegistering'] === true);

        $agent = User::create(['name' => 'Ag', 'email' => 'a@t.dev', 'username' => 'a', 'password' => 'x'])->fresh();
        $agent->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT, 'active' => true])->save();
        $this->actingAs($agent)->get(route('typebot.index'))->assertForbidden();
    }

    public function test_finished_flow_does_not_restart_on_thanks_but_closing_resets(): void
    {
        Http::fake(['*startChat' => Http::response(['sessionId' => 's1', 'messages' => [$this->text('Pronto!')]])]);
        $this->in('oi');
        $this->assertSame('finished', TypebotSession::first()->status);
        $this->in('obrigado');
        Http::assertSentCount(1);

        $this->actingAs($this->owner)->postJson(route('chat.api.close', $this->chat->id))->assertOk();
        $this->assertSame(0, TypebotSession::count());
        $this->in('oi de novo');
        Http::assertSentCount(2);
    }

    public function test_finish_can_hand_over_and_ai_waits_while_the_flow_is_active(): void
    {
        TypebotSetting::where('user_id', $this->owner->id)->update(['on_finish' => 'handoff', 'handoff_message' => 'Já te passo para uma pessoa.', 'handoff_to' => null]);
        Http::fake(['*startChat' => Http::response(['sessionId' => 's1', 'messages' => [$this->text('Obrigado pelos dados')]])]);
        $this->in('oi');
        $this->assertSame('handoff', TypebotSession::first()->status);
        $this->assertContains('Já te passo para uma pessoa.', $this->bot());

        TypebotSession::query()->update(['status' => 'active']);
        $this->assertTrue(TypebotBot::isActive($this->owner->id, $this->chat->contact_id, $this->inst->id));
        TypebotSession::query()->update(['status' => 'finished']);
        $this->assertFalse(TypebotBot::isActive($this->owner->id, $this->chat->contact_id, $this->inst->id));
    }
}
