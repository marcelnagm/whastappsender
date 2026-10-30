<?php

namespace Tests\Feature;

use App\Jobs\SendAiReplyJob;
use App\Models\AiMessage;
use App\Models\AiProfile;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class TelegramChannelTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Storage::fake();
        config(['telegram.api_url' => 'https://tg.test', 'app.url' => 'https://crm.test', 'ai.allow_private_hosts' => false]);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x', 'ai_enabled' => true, 'ai_mode' => 'auto']);
    }

    private function bot(string $token = '111111:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', ?User $owner = null): Instance
    {
        $owner ??= $this->user;

        return Instance::create([
            'user_id' => $owner->id, 'name' => 'Bot', 'instance_name' => 'tg-' . $owner->id . '-' . substr(md5($token), 0, 6), 'status' => 'connected',
            'channel' => 'telegram', 'telegram_token' => $token, 'telegram_username' => 'bot_' . substr(md5($token), 0, 4), 'telegram_secret' => 'sekret',
        ]);
    }

    private function update(string $text = 'oi', int $chatId = 777, int $mid = 10, array $extra = []): array
    {
        return ['update_id' => 1, 'message' => array_merge([
            'message_id' => $mid, 'date' => time(), 'text' => $text,
            'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ana', 'last_name' => 'Souza'],
            'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => 'Ana'],
        ], $extra)];
    }

    public function test_webhook_checks_secret_and_channel(): void
    {
        $bot = $this->bot();
        $wa = Instance::create(['user_id' => $this->user->id, 'name' => 'w', 'instance_name' => 'w1', 'status' => 'connected']);

        $this->postJson("/api/webhooks/telegram/{$bot->id}", $this->update())->assertStatus(401);
        $this->postJson("/api/webhooks/telegram/{$bot->id}", $this->update(), ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertStatus(401);
        $this->postJson("/api/webhooks/telegram/{$wa->id}", $this->update(), ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'])->assertStatus(404);

        $this->postJson("/api/webhooks/telegram/{$bot->id}", $this->update(), ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'])->assertOk();
        $this->assertSame(1, ChatMessage::count());
    }

    public function test_inbound_text_creates_contact_chat_message_and_dedupes(): void
    {
        $bot = $this->bot();
        $h = ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'];
        $this->postJson("/api/webhooks/telegram/{$bot->id}", $this->update('Olá', 777, 10), $h)->assertOk();
        $this->postJson("/api/webhooks/telegram/{$bot->id}", $this->update('Olá', 777, 10), $h)->assertOk();

        $contact = Contact::where('contact', 'tg:777')->first();
        $this->assertSame('telegram', $contact->status);
        $this->assertSame('Ana Souza', $contact->name);
        $chat = Chat::first();
        $this->assertSame('tg:777', $chat->remote_jid);
        $this->assertSame(1, ChatMessage::count());
        $this->assertSame('777:10', ChatMessage::first()->message_id);
        $this->assertSame(1, (int) $chat->unread_count);
    }

    public function test_groups_and_bots_are_ignored(): void
    {
        $bot = $this->bot();
        $group = $this->update();
        $group['message']['chat']['type'] = 'group';
        $fromBot = $this->update();
        $fromBot['message']['from']['is_bot'] = true;

        $h = ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'];
        $this->postJson("/api/webhooks/telegram/{$bot->id}", $group, $h)->assertOk();
        $this->postJson("/api/webhooks/telegram/{$bot->id}", $fromBot, $h)->assertOk();
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_photo_is_downloaded(): void
    {
        $bot = $this->bot();
        Http::fake([
            'tg.test/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/a.jpg', 'file_size' => 5]]),
            'tg.test/file/*' => Http::response('IMAGE'),
        ]);
        $u = $this->update('', 777, 11, ['photo' => [['file_id' => 's', 'file_size' => 1], ['file_id' => 'big', 'file_size' => 5]], 'caption' => 'foto']);
        unset($u['message']['text']);
        $this->postJson("/api/webhooks/telegram/{$bot->id}", $u, ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'])->assertOk();

        $m = ChatMessage::first();
        $this->assertSame('image', $m->type);
        $this->assertSame('foto', $m->body);
        Storage::assertExists($m->media_path);
    }

    public function test_ai_answers_through_the_bot_that_received(): void
    {
        $a = $this->bot('111111:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        $b = $this->bot('222222:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB');
        AiProfile::create([
            'user_id' => $this->user->id, 'name' => 'A', 'enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'api_key' => 'sk-secret-1234', 'temperature' => 0.5, 'max_tokens' => 200, 'system_prompt' => 'x',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Oi Ana!']]], 'usage' => []]),
            'tg.test/bot*/sendChatAction' => Http::response(['ok' => true, 'result' => true]),
            'tg.test/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
        ]);

        $this->postJson("/api/webhooks/telegram/{$b->id}", $this->update('Olá'), ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'])->assertOk();

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('bot222222:BBBB', $sent->first()[0]->url());
        $this->assertStringNotContainsString('111111', $sent->first()[0]->url());
        $this->assertSame('777', (string) $sent->first()[0]['chat_id']);
        $this->assertSame('Oi Ana!', $sent->first()[0]['text']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evo'));

        $this->assertSame('sent', AiMessage::where('role', 'assistant')->first()->status);
        $out = ChatMessage::where('from_me', true)->first();
        $this->assertSame('777:99', $out->message_id);
        $this->assertSame($b->id, (int) $out->instance_id);
    }

    public function test_stop_command_opts_out(): void
    {
        $bot = $this->bot();
        Http::fake();
        $this->postJson("/api/webhooks/telegram/{$bot->id}", $this->update('/stop'), ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'])->assertOk();
        $this->assertSame(1, (int) Contact::first()->ignore_me);
        Http::assertNothingSent();
    }

    public function test_chat_ui_message_goes_out_through_the_bot(): void
    {
        $bot = $this->bot();
        Http::fake(['tg.test/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 55]])]);
        $this->postJson("/api/webhooks/telegram/{$bot->id}", $this->update('Preciso de ajuda', 777, 20), ['X-Telegram-Bot-Api-Secret-Token' => 'sekret']);
        $chat = Chat::first();

        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat), ['text' => 'Já te ajudo'])->assertStatus(201);

        $m = ChatMessage::where('from_me', true)->first();
        $this->assertSame('sent', $m->fresh()->status);
        $this->assertSame('777:55', $m->fresh()->message_id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'bot111111:') && $r['text'] === 'Já te ajudo');
    }

    public function test_telegram_instances_are_excluded_from_whatsapp_pages(): void
    {
        $this->bot();
        Instance::create(['user_id' => $this->user->id, 'name' => 'Zap', 'instance_name' => 'zap1', 'status' => 'connected']);

        $r = $this->actingAs($this->user)->get('/instances');
        $r->assertOk()->assertSee('Zap')->assertDontSee('tg-' . $this->user->id);
    }

    public function test_settings_connect_validates_registers_and_never_renders_token(): void
    {
        Http::fake([
            'tg.test/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'loja_bot', 'first_name' => 'Loja']]),
            'tg.test/bot*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            'tg.test/bot*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $token = '123456:AAHabcdefghijklmnopqrstuvwxyz012345';

        $this->actingAs($this->user)->post(route('telegram.store'), ['token' => 'nope'])->assertSessionHasErrors('token');
        $this->actingAs($this->user)->post(route('telegram.store'), ['token' => $token])->assertRedirect(route('telegram.index'));

        $bot = Instance::where('channel', 'telegram')->first();
        $this->assertSame('loja_bot', $bot->telegram_username);
        $this->assertSame(48, strlen($bot->telegram_secret));
        $this->assertNotSame($token, $bot->getRawOriginal('telegram_token'), 'token is encrypted at rest');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/setWebhook')
            && $r['url'] === "https://crm.test/api/webhooks/telegram/{$bot->id}" && $r['secret_token'] === $bot->telegram_secret);

        $this->actingAs($this->user)->get(route('telegram.index'))->assertOk()->assertSee('@loja_bot')->assertDontSee($token)->assertDontSee($bot->telegram_secret);
        $this->assertArrayNotHasKey('telegram_token', $bot->toArray());

        // same bot twice
        $this->actingAs($this->user)->post(route('telegram.store'), ['token' => $token])->assertSessionHasErrors('token');

        // other user cannot remove it
        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $this->actingAs($other)->delete(route('telegram.destroy', $bot))->assertNotFound();

        $this->actingAs($this->user)->delete(route('telegram.destroy', $bot))->assertRedirect();
        $this->assertSame(0, Instance::where('channel', 'telegram')->count());
    }

    public function test_webhook_failure_rolls_back_the_instance(): void
    {
        Http::fake([
            'tg.test/bot*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'x_bot', 'first_name' => 'X']]),
            'tg.test/bot*/setWebhook' => Http::response(['ok' => false, 'description' => 'bad webhook'], 400),
        ]);
        $token = '123456:AAHabcdefghijklmnopqrstuvwxyz012345';

        $this->actingAs($this->user)->post(route('telegram.store'), ['token' => $token])->assertSessionHasErrors('token');
        $this->assertSame(0, Instance::where('channel', 'telegram')->count());
    }

    public function test_http_app_url_is_refused_and_token_is_scrubbed(): void
    {
        config(['app.url' => 'http://crm.test']);
        Http::fake(['tg.test/bot*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'x_bot']])]);
        $this->actingAs($this->user)->post(route('telegram.store'), ['token' => '123456:AAHabcdefghijklmnopqrstuvwxyz012345'])->assertSessionHasErrors('token');
        $this->assertSame(0, Instance::where('channel', 'telegram')->count());
    }

    public function test_token_is_scrubbed_from_errors(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'description' => 'oops 123456:SECRETSECRETSECRETSECRETSECRETSE'], 401)]);
        try {
            (new \App\Services\Telegram\TelegramClient('123456:SECRETSECRETSECRETSECRETSECRETSE'))->getMe();
            $this->fail('should throw');
        } catch (\App\Services\Telegram\TelegramException $e) {
            $this->assertStringNotContainsString('SECRETSECRET', $e->getMessage());
        }
    }
}
