<?php

namespace Tests\Feature;

use App\Jobs\FetchChatAvatarJob;
use App\Jobs\SendChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Instance;
use App\Models\User;
use App\Services\Chat\ChatMessageRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ChatBackendTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;
    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Storage::fake('local');
        Cache::flush();
        Queue::fake([FetchChatAvatarJob::class]);
        config(['services.whatsapp' => ['protocol' => 'http', 'url' => 'evo.test', 'port' => 8080, 'apikey' => 'k']]);

        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x']);
        $this->instance = Instance::create(['user_id' => $this->user->id, 'name' => 'n', 'instance_name' => '5595111', 'status' => 'connected']);
    }

    private function upsert(string $id, string $text, bool $fromMe = false, string $jid = '5595999@s.whatsapp.net'): ?ChatMessage
    {
        return app(ChatMessageRecorder::class)->recordUpsert('5595111', [
            'key' => ['id' => $id, 'remoteJid' => $jid, 'fromMe' => $fromMe],
            'pushName' => 'Maria',
            'message' => ['conversation' => $text],
            'messageTimestamp' => now()->timestamp,
        ]);
    }

    public function test_incoming_message_creates_chat_updates_preview_and_unread(): void
    {
        $this->upsert('A1', 'Olá');
        $this->upsert('A2', 'Tudo bem?');

        $chat = Chat::first();
        $this->assertSame('5595999', $chat->phone);
        $this->assertSame('Maria', $chat->name);
        $this->assertSame(2, $chat->unread_count);
        $this->assertSame('Tudo bem?', $chat->last_message_preview);
        $this->assertSame(2, ChatMessage::count());
    }

    public function test_duplicate_webhook_does_not_duplicate_the_message(): void
    {
        $this->upsert('A1', 'Olá');
        $this->upsert('A1', 'Olá');

        $this->assertSame(1, ChatMessage::count());
        $this->assertSame(1, Chat::first()->unread_count);
    }

    public function test_groups_and_unknown_instances_are_ignored(): void
    {
        $this->assertNull($this->upsert('G1', 'oi', false, '1203@g.us'));
        $this->assertNull(app(ChatMessageRecorder::class)->recordUpsert('nope', ['key' => ['id' => 'x', 'remoteJid' => '1@s.whatsapp.net']]));
        $this->assertSame(0, Chat::count());
    }

    public function test_chat_list_and_messages_are_scoped_to_the_owner(): void
    {
        $this->upsert('A1', 'Olá');
        $chat = Chat::first();
        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);

        $this->actingAs($this->user)->getJson(route('chat.api.messages', $chat->id))
            ->assertOk()->assertJsonPath('messages.0.body', 'Olá');

        $this->actingAs($other)->getJson(route('chat.api.messages', $chat->id))->assertForbidden();
        $this->actingAs($other)->postJson(route('chat.api.send', $chat->id), ['text' => 'x'])->assertForbidden();
        $this->actingAs($other)->postJson(route('chat.api.read', $chat->id))->assertForbidden();
        $this->actingAs($other)->getJson(route('chat.api.chats', ['instance' => $this->instance->id]))->assertNotFound();
    }

    public function test_pagination_with_before_id(): void
    {
        config(['chat.page_size' => 5]);
        foreach (range(1, 12) as $i) {
            $this->upsert("M$i", "msg $i");
        }
        $chat = Chat::first();

        $first = $this->actingAs($this->user)->getJson(route('chat.api.messages', $chat->id))->assertOk();
        $this->assertCount(5, $first->json('messages'));
        $this->assertTrue($first->json('has_more'));
        $this->assertSame('msg 12', $first->json('messages.4.body'));

        $oldestId = $first->json('messages.0.id');
        $page = $this->actingAs($this->user)->getJson(route('chat.api.messages', ['chat' => $chat->id, 'before_id' => $oldestId]))->assertOk();
        $this->assertSame('msg 7', $page->json('messages.4.body'));
    }

    public function test_send_text_creates_pending_message_and_job_which_calls_evolution(): void
    {
        Queue::fake();
        $this->upsert('A1', 'Olá');
        $chat = Chat::first();

        $res = $this->actingAs($this->user)->postJson(route('chat.api.send', $chat->id), ['text' => 'Oi!', 'client_id' => 'c1'])->assertCreated();
        $id = $res->json('message.id');
        $this->assertSame('pending', ChatMessage::find($id)->status);
        Queue::assertPushed(SendChatMessageJob::class);

        Http::fake(['*/message/sendText/5595111' => Http::response(['key' => ['id' => 'WA-1'], 'status' => 'PENDING'], 201)]);
        (new SendChatMessageJob($id))->handle(app(\App\Services\Chat\EvolutionChatClient::class), app(ChatMessageRecorder::class));

        $msg = ChatMessage::find($id);
        $this->assertSame('sent', $msg->status);
        $this->assertSame('WA-1', $msg->message_id);
        $this->assertSame('c1', $msg->client_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/5595111') && $r['text'] === 'Oi!' && $r['number'] === '5595999');
    }

    public function test_failed_send_marks_error_and_can_be_retried(): void
    {
        Queue::fake();
        $this->upsert('A1', 'Olá');
        $chat = Chat::first();
        $id = $this->actingAs($this->user)->postJson(route('chat.api.send', $chat->id), ['text' => 'Oi'])->json('message.id');

        Http::fake(['*/message/sendText/*' => Http::response(['error' => 'down'], 500)]);
        (new SendChatMessageJob($id))->handle(app(\App\Services\Chat\EvolutionChatClient::class), app(ChatMessageRecorder::class));
        $this->assertSame('error', ChatMessage::find($id)->status);

        Queue::fake();
        $this->actingAs($this->user)->postJson(route('chat.api.retry', $id))->assertOk();
        Queue::assertPushed(SendChatMessageJob::class);
    }

    public function test_send_requires_text_or_file_and_accepts_attachments(): void
    {
        Queue::fake();
        $this->upsert('A1', 'Olá');
        $chat = Chat::first();

        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat->id), ['text' => '  '])->assertStatus(422);

        $res = $this->actingAs($this->user)->post(route('chat.api.send', $chat->id), [
            'text' => 'olha',
            'file' => UploadedFile::fake()->image('foto.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $msg = ChatMessage::find($res->json('message.id'));
        $this->assertSame('image', $msg->type);
        $this->assertSame('olha', $msg->body);
        Storage::disk('local')->assertExists($msg->media_path);
    }

    public function test_mark_read_resets_unread_and_notifies_evolution(): void
    {
        $this->upsert('A1', 'Olá');
        $chat = Chat::first();
        Http::fake(['*' => Http::response([], 200)]);

        $this->actingAs($this->user)->postJson(route('chat.api.read', $chat->id))->assertOk();

        $this->assertSame(0, $chat->fresh()->unread_count);
        $this->assertSame('read', ChatMessage::where('message_id', 'A1')->first()->status);
    }

    public function test_since_returns_only_recently_changed_messages(): void
    {
        $this->upsert('A1', 'velha');
        ChatMessage::query()->update(['updated_at' => now()->subHour()]);
        $this->upsert('A2', 'nova');
        $chat = Chat::first();

        $res = $this->actingAs($this->user)
            ->getJson(route('chat.api.messages', ['chat' => $chat->id, 'since' => now()->subMinute()->toIso8601String()]))
            ->assertOk();

        $this->assertSame(['nova'], array_column($res->json('messages'), 'body'));
    }

    public function test_status_update_advances_message_status(): void
    {
        $this->upsert('OUT1', 'enviada', true);
        app(ChatMessageRecorder::class)->recordStatus('5595111', ['keyId' => 'OUT1', 'remoteJid' => '5595999@s.whatsapp.net', 'status' => 'READ']);

        $this->assertSame('read', ChatMessage::where('message_id', 'OUT1')->first()->status);
    }
}
