<?php

namespace Tests\Feature;

use App\Models\AiMessage;
use App\Models\AiProfile;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\TikTok\TikTokClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class TikTokChannelTest extends TestCase
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
            'tiktok.api_url' => 'https://tt.test/open', 'tiktok.app_id' => 'appid', 'tiktok.app_secret' => 'appsecret',
            'app.url' => 'https://crm.test', 'ai.allow_private_hosts' => false,
        ]);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x', 'ai_enabled' => true, 'ai_mode' => 'auto']);
    }

    private function account(string $openId = 'biz1', ?User $owner = null, array $o = []): Instance
    {
        $owner ??= $this->user;

        return Instance::create($o + [
            'user_id' => $owner->id, 'name' => 'Loja TT', 'instance_name' => 'tt-' . $openId, 'status' => 'connected', 'channel' => 'tiktok',
            'tiktok_open_id' => $openId, 'tiktok_token' => 'acc-' . $openId, 'tiktok_refresh_token' => 'ref-' . $openId,
            'tiktok_token_expires_at' => now()->addHours(5), 'tiktok_refresh_expires_at' => now()->addDays(20),
        ]);
    }

    private function event(array $content, string $business = 'biz1'): array
    {
        return ['event' => 'im_send_msg', 'business_id' => $business, 'content' => json_encode($content + ['timestamp' => time()])];
    }

    private function deliver(array $body, ?int $t = null, string $secret = 'appsecret')
    {
        $raw = json_encode($body);
        $t ??= time();
        $sig = 't=' . $t . ',s=' . hash_hmac('sha256', $t . '.' . $raw, $secret);

        return $this->call('POST', '/api/webhooks/tiktok', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_Tiktok-Signature' => $sig, 'HTTP_ACCEPT' => 'application/json'], $raw);
    }

    private function fakeNames(): void
    {
        Http::fake(['tt.test/open/business/message/content/list/*' => Http::response(['code' => 0, 'data' => ['participants' => [['role' => 'PERSONAL_ACCOUNT', 'display_name' => 'Caio Dança'], ['role' => 'BUSINESS_ACCOUNT', 'display_name' => 'Loja']]]])]);
    }

    public function test_webhook_signature_and_clock(): void
    {
        $this->account();
        $this->fakeNames();
        $b = $this->event(['conversation_id' => 'c1', 'message_id' => 'm1', 'message_type' => 'TEXT', 'text' => ['body' => 'oi'], 'from_user' => 'cust1']);

        $this->deliver($b, null, 'wrong')->assertStatus(401);
        $this->deliver($b, time() - 4000)->assertStatus(401);
        $this->assertSame(0, ChatMessage::count());
        $this->deliver($b)->assertOk();
        $this->assertSame(1, ChatMessage::count());

        config(['tiktok.app_secret' => null]);
        $this->deliver($b)->assertStatus(503);
    }

    public function test_inbound_creates_contact_chat_message_dedupes_and_records_echo(): void
    {
        $this->account();
        $this->fakeNames();
        $b = $this->event(['conversation_id' => 'c1', 'message_id' => 'm1', 'message_type' => 'TEXT', 'text' => ['body' => 'Tem tamanho M?'], 'from_user' => 'cust1']);
        $this->deliver($b)->assertOk();
        $this->deliver($b)->assertOk();

        $contact = Contact::where('contact', 'tt:c1')->first();
        $this->assertSame('tiktok', $contact->status);
        $this->assertSame('Caio Dança', $contact->name);
        $this->assertSame('tt:c1', Chat::first()->remote_jid);
        $this->assertSame(1, ChatMessage::count());

        // the business answered from the TikTok app: same event, sender = business
        $this->deliver($this->event(['conversation_id' => 'c1', 'message_id' => 'm2', 'message_type' => 'TEXT', 'text' => ['body' => 'Tem sim'], 'from_user' => 'biz1']))->assertOk();
        $this->assertTrue(ChatMessage::where('message_id', 'm2')->first()->from_me);
    }

    public function test_image_is_downloaded(): void
    {
        $this->account();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake([
            'tt.test/open/business/message/media/download/*' => Http::response(['code' => 0, 'data' => ['download_url' => 'https://cdn.tt.test/x.png']]),
            'cdn.tt.test/*' => Http::response($png),
            'tt.test/*' => Http::response(['code' => 0, 'data' => ['participants' => []]]),
        ]);
        $this->deliver($this->event(['conversation_id' => 'c1', 'message_id' => 'img1', 'message_type' => 'IMAGE', 'image' => ['media_id' => 'media9'], 'from_user' => 'cust1']))->assertOk();

        $m = ChatMessage::first();
        $this->assertSame('image', $m->type);
        $this->assertSame('image/png', $m->mimetype);
        Storage::assertExists($m->media_path);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'media/download') && $r->hasHeader('Access-Token', 'acc-biz1') && $r['media_id'] === 'media9');
    }

    public function test_ai_answers_through_the_account_that_received(): void
    {
        $this->account('biz1');
        $this->account('biz2');
        AiProfile::create([
            'user_id' => $this->user->id, 'name' => 'A', 'enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'api_key' => 'sk-secret-1234', 'temperature' => 0.5, 'max_tokens' => 200, 'system_prompt' => 'x',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Temos sim!']]], 'usage' => []]),
            'tt.test/open/business/message/send/*' => Http::response(['code' => 0, 'data' => ['message' => ['message_id' => 'out-1']]]),
            'tt.test/*' => Http::response(['code' => 0, 'data' => ['participants' => []]]),
        ]);

        $this->deliver($this->event(['conversation_id' => 'c7', 'message_id' => 'm7', 'message_type' => 'TEXT', 'text' => ['body' => 'Tem M?'], 'from_user' => 'cust'], 'biz2'))->assertOk();

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), '/message/send/') && ($r['message_type'] ?? '') === 'TEXT');
        $this->assertCount(1, $sent);
        $req = $sent->first()[0];
        $this->assertSame('acc-biz2', $req->header('Access-Token')[0]);
        $this->assertSame('biz2', $req['business_id']);
        $this->assertSame('CONVERSATION', $req['recipient_type']);
        $this->assertSame('c7', $req['recipient']);
        $this->assertSame('Temos sim!', $req['text']['body']);

        $this->assertSame('sent', AiMessage::where('role', 'assistant')->first()->status);
        $this->assertSame('out-1', ChatMessage::where('from_me', true)->first()->message_id);

        // echo of our own answer is not stored twice
        $this->deliver($this->event(['conversation_id' => 'c7', 'message_id' => 'out-1', 'message_type' => 'TEXT', 'text' => ['body' => 'Temos sim!'], 'from_user' => 'biz2'], 'biz2'))->assertOk();
        $this->assertSame(1, ChatMessage::where('from_me', true)->count());
    }

    public function test_chat_send_respects_window_and_media_rules(): void
    {
        $this->account();
        Http::fake([
            'tt.test/open/business/message/media/upload/*' => Http::response(['code' => 0, 'data' => ['media_id' => 'up1']]),
            'tt.test/open/business/message/send/*' => Http::response(['code' => 0, 'data' => ['message' => ['message_id' => 'ui-1']]]),
            'tt.test/*' => Http::response(['code' => 0, 'data' => ['participants' => []]]),
        ]);
        $this->deliver($this->event(['conversation_id' => 'c1', 'message_id' => 'm1', 'message_type' => 'TEXT', 'text' => ['body' => 'oi'], 'from_user' => 'cust']))->assertOk();
        $chat = Chat::first();

        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat), ['text' => 'Olá!'])->assertStatus(201);
        $this->assertSame('ui-1', ChatMessage::where('from_me', true)->first()->fresh()->message_id);

        $png = \Illuminate\Http\UploadedFile::fake()->image('a.png', 20, 20);
        $this->actingAs($this->user)->post(route('chat.api.send', $chat), ['file' => $png], ['Accept' => 'application/json'])->assertStatus(201);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'media/upload'));
        Http::assertSent(fn (Request $r) => ($r['message_type'] ?? '') === 'IMAGE' && ($r['image']['media_id'] ?? '') === 'up1');

        $pdf = \Illuminate\Http\UploadedFile::fake()->create('a.pdf', 10, 'application/pdf');
        $this->actingAs($this->user)->post(route('chat.api.send', $chat), ['file' => $pdf], ['Accept' => 'application/json'])->assertStatus(422);

        ChatMessage::where('from_me', false)->update(['message_timestamp' => now()->subHours(50)]);
        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat), ['text' => 'ainda aí?'])->assertStatus(422);
    }

    public function test_token_is_refreshed_before_sending_and_rotation_is_stored(): void
    {
        $i = $this->account('biz1', null, ['tiktok_token_expires_at' => now()->addMinutes(2)]);
        Http::fake([
            'tt.test/open/tt_user/oauth2/refresh_token/*' => Http::response(['code' => 0, 'data' => ['access_token' => 'acc-new', 'refresh_token' => 'ref-new', 'expires_in' => 86400, 'refresh_token_expires_in' => 2592000]]),
            'tt.test/open/business/message/send/*' => Http::response(['code' => 0, 'data' => ['message' => ['message_id' => 'x1']]]),
        ]);

        TikTokClient::for($i)->sendText('c1', 'oi');

        $this->assertSame('acc-new', $i->fresh()->tiktok_token);
        $this->assertSame('ref-new', $i->fresh()->tiktok_refresh_token);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/message/send/') && $r->hasHeader('Access-Token', 'acc-new'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'refresh_token') && $r['refresh_token'] === 'ref-biz1');

        $i->update(['tiktok_token_expires_at' => now()->subMinute(), 'tiktok_refresh_expires_at' => now()->subDay()]);
        $this->expectException(\App\Services\TikTok\TikTokException::class);
        TikTokClient::for($i->fresh())->sendText('c1', 'oi');
    }

    public function test_api_error_with_http_200_is_a_failure_and_secrets_are_scrubbed(): void
    {
        $i = $this->account();
        Http::fake(['tt.test/*' => Http::response(['code' => 40001, 'message' => 'bad acc-biz1 token'], 200)]);
        try {
            TikTokClient::for($i)->sendText('c1', 'oi');
            $this->fail('should throw');
        } catch (\App\Services\TikTok\TikTokException $e) {
            $this->assertStringNotContainsString('acc-biz1', $e->getMessage());
        }
    }

    public function test_oauth_connect_callback_reauthorize_and_ownership(): void
    {
        Http::fake(['tt.test/open/tt_user/oauth2/token/*' => Http::response(['code' => 0, 'data' => [
            'access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 86400, 'refresh_token_expires_in' => 2592000, 'open_id' => 'bizX', 'display_name' => 'Minha Loja TT',
        ]])]);

        $r = $this->actingAs($this->user)->get(route('tiktok.connect'));
        $r->assertRedirect();
        $loc = $r->headers->get('Location');
        $this->assertStringStartsWith('https://business-api.tiktok.com/portal/auth?', $loc);
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame('appid', $q['app_id']);
        $this->assertSame('https://crm.test/tiktok/callback', $q['redirect_uri']);

        $this->actingAs($this->user)->get(route('tiktok.callback', ['auth_code' => 'CODE', 'state' => 'forged']))->assertSessionHas('error');
        $this->assertSame(0, Instance::where('channel', 'tiktok')->count());

        $this->actingAs($this->user)->get(route('tiktok.callback', ['auth_code' => 'CODE', 'state' => $q['state']]))->assertRedirect(route('tiktok.index'))->assertSessionHas('success');
        $acc = Instance::where('channel', 'tiktok')->first();
        $this->assertSame('bizX', $acc->tiktok_open_id);
        $this->assertSame('A1', $acc->tiktok_token);
        $this->assertNotSame('A1', $acc->getRawOriginal('tiktok_token'), 'encrypted at rest');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2/token') && $r['auth_code'] === 'CODE' && $r['client_secret'] === 'appsecret');

        // authorising the same account again renews instead of duplicating
        $state2 = Crypt::encryptString(json_encode(['u' => $this->user->id, 'exp' => time() + 600]));
        $this->actingAs($this->user)->get(route('tiktok.callback', ['auth_code' => 'CODE2', 'state' => $state2]))->assertSessionHas('success');
        $this->assertSame(1, Instance::where('channel', 'tiktok')->count());

        // another user cannot take the account
        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $state3 = Crypt::encryptString(json_encode(['u' => $other->id, 'exp' => time() + 600]));
        $this->actingAs($other)->get(route('tiktok.callback', ['auth_code' => 'CODE3', 'state' => $state3]))->assertSessionHas('error');
        $this->assertSame(1, Instance::where('channel', 'tiktok')->count());

        // expired state
        $old = Crypt::encryptString(json_encode(['u' => $this->user->id, 'exp' => time() - 1]));
        $this->actingAs($this->user)->get(route('tiktok.callback', ['auth_code' => 'C', 'state' => $old]))->assertSessionHas('error');

        $page = $this->actingAs($this->user)->get(route('tiktok.index'))->assertOk()->assertSee('Minha Loja TT')->assertSee('https://crm.test/api/webhooks/tiktok');
        $page->assertDontSee('A1')->assertDontSee('R1');
        $this->assertArrayNotHasKey('tiktok_token', $acc->toArray());
        $this->assertArrayNotHasKey('tiktok_refresh_token', $acc->toArray());

        $this->actingAs($other)->delete(route('tiktok.destroy', $acc))->assertNotFound();
        $this->actingAs($this->user)->delete(route('tiktok.destroy', $acc))->assertRedirect();
        $this->assertSame(0, Instance::where('channel', 'tiktok')->count());
    }

    public function test_unconfigured_server_refuses_to_connect(): void
    {
        config(['tiktok.app_id' => null]);
        $this->actingAs($this->user)->get(route('tiktok.connect'))->assertRedirect(route('tiktok.index'))->assertSessionHas('error');
    }
}
