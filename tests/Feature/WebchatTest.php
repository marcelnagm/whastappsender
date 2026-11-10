<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class WebchatTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Instance $w;
    private string $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        Queue::fake();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->v = str_repeat('ab12', 8);
    }

    private function widget(array $cfg = []): Instance
    {
        return $this->w = Instance::create(['user_id' => $this->owner->id, 'name' => 'Loja', 'instance_name' => 'wc' . str_repeat('a', 24), 'status' => 'connected', 'channel' => 'webchat',
            'webchat_config' => $cfg + ['title' => 'Fale conosco', 'welcome' => 'Oi!', 'color' => '#ff0000', 'origins' => '', 'ask_contact' => true, 'active' => true]]);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/public/webchat/' . $this->w->instance_name . $suffix;
    }

    public function test_owner_creates_edits_and_removes_a_widget_and_gets_the_snippet(): void
    {
        $this->actingAs($this->owner)->post(route('webchat.store'), ['name' => 'Minha loja'])->assertRedirect();
        $w = Instance::where('channel', 'webchat')->firstOrFail();
        $this->assertSame('connected', $w->status);
        $this->assertFalse($w->isWhatsapp());

        $this->actingAs($this->owner)->get(route('webchat.index'))->assertOk()->assertSee('/w/' . $w->instance_name . '.js', false);
        $this->actingAs($this->owner)->put(route('webchat.update', $w->id), ['name' => 'Loja 2', 'title' => 'Oi', 'welcome' => 'Olá', 'color' => '#00ff00', 'origins' => 'https://loja.com/', 'active' => 1])->assertRedirect();
        $this->assertSame('loja.com', \App\Services\Webchat\Webchat::origins($w->fresh())[0]);

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->delete(route('webchat.destroy', $w->id))->assertStatus(404);
        $this->actingAs($this->owner)->delete(route('webchat.destroy', $w->id))->assertRedirect();
        $this->assertSame(0, Instance::where('channel', 'webchat')->count());
    }

    public function test_script_and_definition_are_public_and_inactive_widgets_disappear(): void
    {
        $this->widget();
        $this->get('/w/' . $this->w->instance_name . '.js')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->assertSee($this->w->instance_name, false);
        $this->getJson($this->url())->assertOk()->assertJsonPath('title', 'Fale conosco')->assertJsonPath('color', '#ff0000');

        $this->w->update(['webchat_config' => array_merge($this->w->webchat_config, ['active' => false])]);
        $this->getJson($this->url())->assertStatus(403);
        $this->get('/w/' . $this->w->instance_name . '.js')->assertNotFound();
    }

    public function test_allowed_sites_are_enforced(): void
    {
        $this->widget(['origins' => 'loja.com']);
        $this->getJson($this->url(), ['Origin' => 'https://evil.com'])->assertStatus(403);
        $this->getJson($this->url(), ['Origin' => 'https://www.loja.com'])->assertOk();
        $this->getJson($this->url())->assertStatus(403); // no origin at all is not an allowed site
    }

    public function test_visitor_message_becomes_a_contact_and_a_chat_and_the_team_answer_comes_back(): void
    {
        $this->widget();
        $this->postJson($this->url('/messages'), ['visitor' => $this->v, 'text' => 'Quanto custa?', 'name' => 'Ana <b>X</b>', 'email' => 'ana@x.com', 'page' => 'https://loja.com/p', 'client_id' => 'c1'])->assertOk();
        $this->postJson($this->url('/messages'), ['visitor' => $this->v, 'text' => 'Quanto custa?', 'client_id' => 'c1'])->assertOk(); // retry is not duplicated

        $contact = Contact::where('contact', 'wc:' . $this->v)->firstOrFail();
        $this->assertSame('Ana X', $contact->name);
        $this->assertSame('ana@x.com', $contact->email);
        $chat = Chat::where('remote_jid', 'wc:' . $this->v)->firstOrFail();
        $this->assertSame(1, $chat->unread_count);
        $this->assertSame(1, ChatMessage::where('chat_id', $chat->id)->count());
        $this->assertStringContainsString('loja.com/p', ChatMessage::first()->body);

        // the team answers from the inbox
        $this->actingAs($this->owner)->postJson(route('chat.api.send', $chat->id), ['text' => 'R$ 10'])->assertStatus(201);
        $msgs = $this->getJson($this->url('/messages?visitor=' . $this->v))->assertOk()->json('messages');
        $this->assertSame(['visitor', 'agent'], array_column($msgs, 'from'));
        $this->assertSame('R$ 10', $msgs[1]['text']);
        $this->assertSame([], $this->getJson($this->url('/messages?visitor=' . $this->v . '&after=' . $msgs[1]['id']))->json('messages'));
    }

    public function test_a_visitor_never_sees_another_visitors_conversation_and_bad_input_is_refused(): void
    {
        $this->widget();
        $this->postJson($this->url('/messages'), ['visitor' => $this->v, 'text' => 'segredo'])->assertOk();
        $other = str_repeat('cd34', 8);

        $this->assertSame([], $this->getJson($this->url('/messages?visitor=' . $other))->json('messages'));
        $this->getJson($this->url('/messages?visitor=../x'))->assertStatus(403);
        $this->postJson($this->url('/messages'), ['visitor' => 'short', 'text' => 'x'])->assertStatus(422);
        $this->postJson($this->url('/messages'), ['visitor' => $this->v, 'text' => str_repeat('a', 1001)])->assertStatus(422);
        $this->postJson($this->url('/messages'), ['visitor' => $this->v, 'text' => 'spam', '_hp' => 'bot'])->assertOk();
        $this->assertSame(1, ChatMessage::count());
    }

    public function test_chat_shows_the_channel_and_blocks_files(): void
    {
        $this->widget();
        $this->postJson($this->url('/messages'), ['visitor' => $this->v, 'text' => 'oi'])->assertOk();
        $chat = Chat::firstOrFail();
        $file = \Illuminate\Http\UploadedFile::fake()->create('a.pdf', 10, 'application/pdf');
        $this->actingAs($this->owner)->post(route('chat.api.send', $chat->id), ['file' => $file], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
