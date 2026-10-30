<?php

namespace Tests\Feature;

use App\Models\AiMessage;
use App\Models\AiProfile;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramInbound;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class InstagramChannelTest extends TestCase
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
            'instagram.graph_url' => 'https://ig.test', 'instagram.app_secret' => 'appsecret', 'instagram.verify_token' => 'vtoken',
            'app.url' => 'https://crm.test', 'ai.allow_private_hosts' => false,
        ]);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x', 'ai_enabled' => true, 'ai_mode' => 'auto']);
    }

    private function account(string $igId = '9001', ?User $owner = null): Instance
    {
        $owner ??= $this->user;

        return Instance::create([
            'user_id' => $owner->id, 'name' => 'Loja', 'instance_name' => 'ig-' . $igId, 'status' => 'connected', 'channel' => 'instagram',
            'instagram_token' => 'IGAAtoken' . $igId, 'instagram_id' => $igId, 'instagram_username' => 'loja' . $igId,
        ]);
    }

    private function payload(array $message, string $sender = '555', string $account = '9001', bool $echo = false): array
    {
        $event = ['sender' => ['id' => $echo ? $account : $sender], 'recipient' => ['id' => $echo ? $sender : $account], 'timestamp' => time() * 1000, 'message' => $message];

        return ['object' => 'instagram', 'entry' => [['id' => $account, 'time' => time(), 'messaging' => [$event]]]];
    }

    private function deliver(array $payload, ?string $secret = 'appsecret')
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, $secret ?? 'x')];

        return $this->call('POST', '/api/webhooks/instagram', [], [], [], $headers + ['HTTP_ACCEPT' => 'application/json'], $body);
    }

    private function fakeProfile(): void
    {
        Http::fake(['ig.test/*/555*' => Http::response(['name' => 'Bia Lima', 'username' => 'bia'])]);
    }

    public function test_verification_handshake(): void
    {
        $this->get('/api/webhooks/instagram?hub_mode=subscribe&hub_verify_token=vtoken&hub_challenge=abc123')->assertOk()->assertSee('abc123');
        $this->get('/api/webhooks/instagram?hub_mode=subscribe&hub_verify_token=nope&hub_challenge=abc123')->assertStatus(403);
        config(['instagram.verify_token' => null]);
        $this->get('/api/webhooks/instagram?hub_mode=subscribe&hub_verify_token=&hub_challenge=x')->assertStatus(403);
    }

    public function test_signature_is_required(): void
    {
        $this->account();
        $this->fakeProfile();
        $p = $this->payload(['mid' => 'm1', 'text' => 'oi']);

        $this->deliver($p, 'wrong')->assertStatus(401);
        $this->assertSame(0, ChatMessage::count());

        $this->deliver($p)->assertOk();
        $this->assertSame(1, ChatMessage::count());

        config(['instagram.app_secret' => null]);
        $this->deliver($p)->assertStatus(503);
    }

    public function test_inbound_creates_contact_chat_message_and_dedupes(): void
    {
        $this->account();
        $this->fakeProfile();
        $p = $this->payload(['mid' => 'm1', 'text' => 'Olá, quanto custa?']);
        $this->deliver($p)->assertOk();
        $this->deliver($p)->assertOk();

        $contact = Contact::where('contact', 'ig:555')->first();
        $this->assertSame('instagram', $contact->status);
        $this->assertSame('Bia Lima', $contact->name);
        $chat = Chat::first();
        $this->assertSame('ig:555', $chat->remote_jid);
        $this->assertSame(1, ChatMessage::count());
        $this->assertSame(1, (int) $chat->unread_count);
    }

    public function test_echo_of_a_message_sent_from_the_app_is_recorded_as_outgoing_once(): void
    {
        $this->account();
        $this->fakeProfile();
        $p = $this->payload(['mid' => 'e1', 'text' => 'respondi pelo app', 'is_echo' => true], '555', '9001', true);
        $this->deliver($p)->assertOk();
        $this->deliver($p)->assertOk();

        $m = ChatMessage::first();
        $this->assertTrue($m->from_me);
        $this->assertSame(1, ChatMessage::count());

        InstagramInbound::rememberSent(['sent-1']);
        $this->deliver($this->payload(['mid' => 'sent-1', 'text' => 'x', 'is_echo' => true], '555', '9001', true))->assertOk();
        $this->assertSame(1, ChatMessage::count(), 'echo of our own send is ignored');
    }

    public function test_image_is_downloaded_and_stored(): void
    {
        $this->account();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake(['ig.test/*/555*' => Http::response(['username' => 'bia']), 'cdn.test/*' => Http::response($png)]);
        $this->deliver($this->payload(['mid' => 'm2', 'text' => 'olha', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.test/a.png']]]]))->assertOk();

        $m = ChatMessage::first();
        $this->assertSame('image', $m->type);
        $this->assertSame('olha', $m->body);
        $this->assertSame('image/png', $m->mimetype);
        Storage::assertExists($m->media_path);
    }

    public function test_long_mid_is_hashed_to_fit_the_column(): void
    {
        $mid = str_repeat('a', 200);
        $this->assertLessThanOrEqual(120, strlen(InstagramInbound::msgId($mid)));
        $this->assertSame('short', InstagramInbound::msgId('short'));
    }

    public function test_ai_answers_through_the_account_that_received(): void
    {
        $a = $this->account('9001');
        $b = $this->account('9002');
        AiProfile::create([
            'user_id' => $this->user->id, 'name' => 'A', 'enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'api_key' => 'sk-secret-1234', 'temperature' => 0.5, 'max_tokens' => 200, 'system_prompt' => 'x',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Oi Bia, custa R$ 10.']]], 'usage' => []]),
            'ig.test/*/me/messages' => Http::response(['message_id' => 'out-1', 'recipient_id' => '555']),
            'ig.test/*' => Http::response(['username' => 'bia']),
        ]);

        $this->deliver($this->payload(['mid' => 'm9', 'text' => 'Quanto custa?'], '555', '9002'))->assertOk();

        $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/me/messages') && isset($r['message']['text']));
        $this->assertCount(1, $sent);
        $req = $sent->first()[0];
        $this->assertSame('Bearer IGAAtoken9002', $req->header('Authorization')[0]);
        $this->assertSame('555', $req['recipient']['id']);
        $this->assertSame('Oi Bia, custa R$ 10.', $req['message']['text']);

        $this->assertSame('sent', AiMessage::where('role', 'assistant')->first()->status);
        $out = ChatMessage::where('from_me', true)->first();
        $this->assertSame('out-1', $out->message_id);
        $this->assertSame($b->id, (int) $out->instance_id);
        $this->assertNotSame($a->id, (int) $out->instance_id);

        // the echo webhook of that very message creates nothing new
        $this->deliver($this->payload(['mid' => 'out-1', 'text' => 'Oi Bia, custa R$ 10.', 'is_echo' => true], '555', '9002', true))->assertOk();
        $this->assertSame(1, ChatMessage::where('from_me', true)->count());
    }

    public function test_chat_send_goes_through_the_account_and_respects_the_window(): void
    {
        $this->account();
        Http::fake(['ig.test/*/555*' => Http::response(['username' => 'bia']), 'ig.test/*/me/messages' => Http::response(['message_id' => 'ui-1'])]);
        $this->deliver($this->payload(['mid' => 'm5', 'text' => 'preciso de ajuda']))->assertOk();
        $chat = Chat::first();

        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat), ['text' => 'Já te ajudo'])->assertStatus(201);
        $this->assertSame('ui-1', ChatMessage::where('from_me', true)->first()->fresh()->message_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/me/messages') && ($r['message']['text'] ?? null) === 'Já te ajudo');

        // customer silent for more than the window
        ChatMessage::where('from_me', false)->update(['message_timestamp' => now()->subHours(30)]);
        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat), ['text' => 'e agora?'])->assertStatus(422);
    }

    public function test_media_sent_from_chat_uses_a_signed_link(): void
    {
        $this->account();
        Http::fake(['ig.test/*/555*' => Http::response(['username' => 'bia']), 'ig.test/*/me/messages' => Http::response(['message_id' => 'img-1'])]);
        $this->deliver($this->payload(['mid' => 'm6', 'text' => 'foto?']))->assertOk();
        $chat = Chat::first();

        $file = \Illuminate\Http\UploadedFile::fake()->image('p.png');
        $this->actingAs($this->user)->post(route('chat.api.send', $chat), ['file' => $file, 'text' => 'segue'], ['Accept' => 'application/json'])->assertStatus(201);

        $req = Http::recorded(fn (Request $r) => isset($r['message']['attachment']))->first()[0];
        $this->assertSame('image', $req['message']['attachment']['type']);
        $url = $req['message']['attachment']['payload']['url'];
        $this->assertStringContainsString('signature=', $url);

        auth()->logout();
        $this->get($url)->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $url))->assertStatus(403);
    }

    public function test_chat_api_exposes_the_channel_and_whatsapp_pages_exclude_instagram(): void
    {
        $this->account();
        $this->fakeProfile();
        $this->deliver($this->payload(['mid' => 'm7', 'text' => 'oi']))->assertOk();
        $ig = Instance::where('channel', 'instagram')->first();

        $r = $this->actingAs($this->user)->getJson(route('chat.api.chats', ['instance' => $ig->id]))->assertOk();
        $this->assertSame('instagram', $r->json('chats.0.channel'));

        Instance::create(['user_id' => $this->user->id, 'name' => 'Zap', 'instance_name' => 'zap1', 'status' => 'connected']);
        $this->actingAs($this->user)->get('/instances')->assertOk()->assertSee('Zap')->assertDontSee('ig-' . $this->user->id);
    }

    public function test_cannot_start_chat_by_phone_nor_import_history_on_instagram(): void
    {
        $ig = $this->account();
        $this->actingAs($this->user)->postJson(route('chat.api.open'), ['instance_id' => $ig->id, 'phone' => '5595981110695'])->assertStatus(422);
        $this->actingAs($this->user)->postJson(route('chat.api.sync', $ig->id))->assertStatus(422);
    }

    public function test_settings_connect_refresh_remove(): void
    {
        Http::fake([
            'ig.test/refresh_access_token*' => Http::response(['access_token' => 'IGAAnewtoken-new-new-new-1234567890']),
            'ig.test/*/me/subscribed_apps*' => Http::response(['success' => true]),
            'ig.test/*/me*' => Http::response(['user_id' => '7007', 'username' => 'minhaloja', 'name' => 'Minha Loja']),
        ]);
        $token = 'IGAAabcdefghijklmnopqrstuvwxyz0123456789';

        $this->actingAs($this->user)->post(route('instagram.store'), ['token' => 'short'])->assertSessionHasErrors('token');
        $this->actingAs($this->user)->post(route('instagram.store'), ['token' => $token])->assertRedirect(route('instagram.index'));

        $acc = Instance::where('channel', 'instagram')->first();
        $this->assertSame('7007', $acc->instagram_id);
        $this->assertNotSame($token, $acc->getRawOriginal('instagram_token'), 'encrypted at rest');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'subscribed_apps') && $r->method() === 'POST');

        $this->actingAs($this->user)->get(route('instagram.index'))->assertOk()
            ->assertSee('@minhaloja')->assertSee('https://crm.test/api/webhooks/instagram')->assertDontSee($token);
        $this->assertArrayNotHasKey('instagram_token', $acc->toArray());

        $this->actingAs($this->user)->post(route('instagram.store'), ['token' => $token])->assertSessionHasErrors('token'); // same account twice

        $this->actingAs($this->user)->post(route('instagram.refresh', $acc))->assertSessionHas('success');
        $this->assertSame('IGAAnewtoken-new-new-new-1234567890', $acc->fresh()->instagram_token);

        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $this->actingAs($other)->delete(route('instagram.destroy', $acc))->assertNotFound();

        $this->actingAs($this->user)->delete(route('instagram.destroy', $acc))->assertRedirect();
        $this->assertSame(0, Instance::where('channel', 'instagram')->count());
    }

    public function test_unconfigured_server_and_failed_subscription_do_not_leave_an_account(): void
    {
        $token = 'IGAAabcdefghijklmnopqrstuvwxyz0123456789';
        config(['instagram.app_secret' => null]);
        $this->actingAs($this->user)->post(route('instagram.store'), ['token' => $token])->assertSessionHasErrors('token');

        config(['instagram.app_secret' => 'appsecret']);
        Http::fake([
            'ig.test/*/me/subscribed_apps*' => Http::response(['error' => ['message' => 'no permission']], 400),
            'ig.test/*/me*' => Http::response(['user_id' => '7008', 'username' => 'x']),
        ]);
        $this->actingAs($this->user)->post(route('instagram.store'), ['token' => $token])->assertSessionHasErrors('token');
        $this->assertSame(0, Instance::where('channel', 'instagram')->count());
    }

    public function test_text_is_split_and_token_scrubbed(): void
    {
        $parts = InstagramClient::chunk(str_repeat('palavra ', 400));
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(1000, mb_strlen($p));
        }

        Http::fake(['ig.test/*' => Http::response(['error' => ['message' => 'bad token IGAAsecretsecretsecret']], 401)]);
        try {
            (new InstagramClient('IGAAsecretsecretsecret'))->me();
            $this->fail('should throw');
        } catch (\App\Services\Instagram\InstagramException $e) {
            $this->assertStringNotContainsString('secretsecret', $e->getMessage());
        }
    }
}
