<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\Channels\ContactLinks;
use App\Services\Channels\ReplyWindow;
use App\Services\Facebook\FacebookInbound;
use App\Services\Stats\ChannelStats;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class FacebookChannelTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Storage::fake();
        Cache::flush();
        config([
            'facebook.graph_url' => 'https://fb.test', 'facebook.app_secret' => 'appsecret', 'facebook.verify_token' => 'vtoken',
            'app.url' => 'https://crm.test', 'ai.allow_private_hosts' => false,
        ]);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x', 'ai_enabled' => true, 'ai_mode' => 'auto']);
    }

    private function page(string $pageId = '7001', ?User $owner = null): Instance
    {
        $owner ??= $this->user;

        return Instance::create([
            'user_id' => $owner->id, 'name' => 'Minha Página', 'instance_name' => 'fb-' . $pageId, 'status' => 'connected', 'channel' => 'facebook',
            'facebook_token' => 'EAAtoken' . $pageId, 'facebook_page_id' => $pageId, 'facebook_page_name' => 'Minha Página',
        ]);
    }

    private function payload(array $message, string $sender = '888', string $page = '7001', bool $echo = false): array
    {
        $event = ['sender' => ['id' => $echo ? $page : $sender], 'recipient' => ['id' => $echo ? $sender : $page], 'timestamp' => time() * 1000, 'message' => $message];

        return ['object' => 'page', 'entry' => [['id' => $page, 'time' => time(), 'messaging' => [$event]]]];
    }

    private function deliver(array $payload, ?string $secret = 'appsecret')
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, $secret ?? 'x')];

        return $this->call('POST', '/api/webhooks/facebook', [], [], [], $headers + ['HTTP_ACCEPT' => 'application/json'], $body);
    }

    private function fakeProfile(): void
    {
        Http::fake(['fb.test/*/888*' => Http::response(['first_name' => 'Caio', 'last_name' => 'Souza'])]);
    }

    public function test_verification_handshake_and_signature(): void
    {
        $this->get('/api/webhooks/facebook?hub_mode=subscribe&hub_verify_token=vtoken&hub_challenge=abc')->assertOk()->assertSee('abc');
        $this->get('/api/webhooks/facebook?hub_mode=subscribe&hub_verify_token=nope&hub_challenge=abc')->assertStatus(403);

        $this->page();
        $this->fakeProfile();
        $p = $this->payload(['mid' => 'm1', 'text' => 'oi']);
        $this->deliver($p, 'wrong')->assertStatus(401);
        $this->assertSame(0, ChatMessage::count());
        $this->deliver($p)->assertOk();
        $this->assertSame(1, ChatMessage::count());

        config(['facebook.app_secret' => null]);
        $this->deliver($p)->assertStatus(503);
    }

    public function test_inbound_creates_contact_chat_and_dedupes_and_ignores_instagram_events(): void
    {
        $this->page();
        $this->fakeProfile();
        $p = $this->payload(['mid' => 'm1', 'text' => 'Olá, tem estoque?']);
        $this->deliver($p)->assertOk();
        $this->deliver($p)->assertOk();

        $contact = Contact::where('contact', 'fb:888')->first();
        $this->assertSame('facebook', $contact->status);
        $this->assertSame('Caio Souza', $contact->name);
        $chat = Chat::first();
        $this->assertSame('fb:888', $chat->remote_jid);
        $this->assertSame(1, ChatMessage::count());

        // an Instagram-shaped payload does not create anything for this page
        $ig = $this->payload(['mid' => 'x', 'text' => 'y']);
        $ig['object'] = 'instagram';
        $this->deliver($ig)->assertOk();
        $this->assertSame(1, ChatMessage::count());
    }

    public function test_echo_is_outgoing_once_and_our_own_sends_are_ignored(): void
    {
        $this->page();
        $this->fakeProfile();
        $p = $this->payload(['mid' => 'e1', 'text' => 'respondi pelo Inbox', 'is_echo' => true], '888', '7001', true);
        $this->deliver($p)->assertOk();
        $this->deliver($p)->assertOk();
        $this->assertTrue(ChatMessage::first()->from_me);
        $this->assertSame(1, ChatMessage::count());

        FacebookInbound::rememberSent(['sent-1']);
        $this->deliver($this->payload(['mid' => 'sent-1', 'text' => 'x', 'is_echo' => true], '888', '7001', true))->assertOk();
        $this->assertSame(1, ChatMessage::count());
    }

    public function test_chat_send_goes_through_the_page_and_respects_the_window(): void
    {
        $this->page();
        Http::fake(['fb.test/*/888*' => Http::response(['name' => 'Caio']), 'fb.test/*/me/messages' => Http::response(['message_id' => 'ui-1'])]);
        $this->deliver($this->payload(['mid' => 'm5', 'text' => 'preciso de ajuda']))->assertOk();
        $chat = Chat::first();

        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat), ['text' => 'Já te ajudo'])->assertStatus(201);
        $this->assertSame('ui-1', ChatMessage::where('from_me', true)->first()->fresh()->message_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/me/messages') && ($r['message']['text'] ?? null) === 'Já te ajudo'
            && $r['messaging_type'] === 'RESPONSE' && $r['recipient']['id'] === '888');

        ChatMessage::where('from_me', false)->update(['message_timestamp' => now()->subHours(30)]);
        $this->assertFalse(ReplyWindow::isOpen($chat->fresh('instance')));
        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat), ['text' => 'e agora?'])->assertStatus(422);
    }

    public function test_settings_connect_renew_remove_and_token_never_shown(): void
    {
        Http::fake([
            'fb.test/*/me/subscribed_apps*' => Http::response(['success' => true]),
            'fb.test/*/me*' => Http::response(['id' => '7001', 'name' => 'Minha Página']),
        ]);
        $token = 'EAAabcdefghijklmnopqrstuvwxyz0123456789';

        $this->actingAs($this->user)->post(route('facebook.store'), ['token' => 'short'])->assertSessionHasErrors('token');
        $this->actingAs($this->user)->post(route('facebook.store'), ['token' => $token])->assertRedirect(route('facebook.index'));

        $page = Instance::where('channel', 'facebook')->first();
        $this->assertSame('7001', $page->facebook_page_id);
        $this->assertNotSame($token, $page->getRawOriginal('facebook_token'), 'encrypted at rest');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'subscribed_apps') && $r->method() === 'POST');

        $this->actingAs($this->user)->get(route('facebook.index'))->assertOk()->assertSee('Minha Página')->assertSee('https://crm.test/api/webhooks/facebook')->assertDontSee($token);
        $this->assertArrayNotHasKey('facebook_token', $page->toArray());

        // same page twice
        $this->actingAs($this->user)->post(route('facebook.store'), ['token' => $token])->assertSessionHasErrors('token');
        $this->assertSame(1, Instance::where('channel', 'facebook')->count());

        $this->actingAs($this->user)->post(route('facebook.refresh', $page))->assertSessionHas('success');

        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $this->actingAs($other)->delete(route('facebook.destroy', $page))->assertNotFound();
        $this->actingAs($this->user)->delete(route('facebook.destroy', $page))->assertRedirect(route('facebook.index'));
        $this->assertSame(0, Instance::where('channel', 'facebook')->count());
    }

    public function test_rejected_token_is_not_saved_and_not_leaked(): void
    {
        $token = 'EAAsecretsecretsecretsecretsecret1234';
        Http::fake(['fb.test/*' => Http::response(['error' => ['message' => "Invalid OAuth access token {$token}"]], 400)]);
        $r = $this->actingAs($this->user)->post(route('facebook.store'), ['token' => $token]);
        $r->assertSessionHasErrors('token');
        $this->assertStringNotContainsString($token, json_encode(session('errors')->getBag('default')->all()));
        $this->assertSame(0, Instance::where('channel', 'facebook')->count());
    }

    public function test_api_connect_channel_listing_stats_and_links(): void
    {
        Http::fake([
            'fb.test/*/me/subscribed_apps*' => Http::response(['success' => true]),
            'fb.test/*/me*' => Http::response(['id' => '7002', 'name' => 'Loja']),
        ]);
        $this->user->forceFill(['role' => 'admin'])->save();
        \Laravel\Sanctum\Sanctum::actingAs($this->user->fresh());

        $this->postJson('/api/v1/channels/facebook', ['token' => 'EAAabcdefghijklmnopqrstuvwxyz0123456789'])->assertCreated()->assertJsonPath('data.channel', 'facebook');
        $this->postJson('/api/v1/tools/call', ['name' => 'connect_facebook_page', 'arguments' => ['token' => 'EAAabcdefghijklmnopqrstuvwxyz0123456789']])->assertStatus(422); // same page
        $this->getJson('/api/v1/channels?channel=facebook')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/instances/' . Instance::first()->id)->assertStatus(422);

        $this->assertContains('facebook', ChannelStats::CHANNELS);
        $this->assertArrayHasKey('facebook', $this->getJson('/api/v1/channels/stats')->json('data.channels'));

        // link a Facebook contact to a WhatsApp contact
        $wa = Contact::create(['user_id' => $this->user->id, 'contact' => '5511999990000', 'name' => 'Caio', 'status' => 'ativo']);
        $fb = Contact::create(['user_id' => $this->user->id, 'contact' => 'fb:888', 'name' => 'Caio FB', 'status' => 'facebook']);
        $this->assertSame('facebook', ContactLinks::channelOf($fb));
        $this->postJson("/api/v1/contacts/{$fb->id}/link", ['linked_contact_id' => $wa->id])->assertOk();
        $this->assertSame($wa->id, (int) $fb->fresh()->linked_contact_id);
    }
}
