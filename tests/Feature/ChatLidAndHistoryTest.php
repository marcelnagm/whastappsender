<?php

namespace Tests\Feature;

use App\Jobs\FetchChatAvatarJob;
use App\Jobs\ResolveLidChatJob;
use App\Jobs\SyncChatHistoryJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Chat\LidResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ChatLidAndHistoryTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;
    private Instance $instance;
    private const LID = '99887766@lid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
        Queue::fake([FetchChatAvatarJob::class, ResolveLidChatJob::class]);
        config(['services.whatsapp' => ['protocol' => 'http', 'url' => 'evo.test', 'port' => 8080, 'apikey' => 'k']]);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x']);
        $this->instance = Instance::create(['user_id' => $this->user->id, 'name' => 'n', 'instance_name' => '5595111', 'status' => 'connected']);
    }

    private function record(string $id, string $jid, array $keyExtra = [], bool $history = false, string $text = 'oi', bool $fromMe = false)
    {
        return app(ChatMessageRecorder::class)->recordUpsert('5595111', [
            'key' => ['id' => $id, 'remoteJid' => $jid, 'fromMe' => $fromMe] + $keyExtra,
            'pushName' => 'Maria',
            'message' => ['conversation' => $text],
            'messageTimestamp' => now()->timestamp,
        ], $history);
    }

    public function test_lid_message_with_phone_in_key_goes_to_the_phone_chat(): void
    {
        $this->record('A1', self::LID, ['remoteJidAlt' => '5595981110695@s.whatsapp.net']);

        $chat = Chat::first();
        $this->assertSame('5595981110695@s.whatsapp.net', $chat->remote_jid);
        $this->assertSame('5595981110695', $chat->phone);
        $this->assertSame(1, Chat::count());
    }

    public function test_existing_lid_chat_is_converted_when_the_phone_becomes_known(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->record('A1', self::LID);          // phone unknown: opened under the lid
        $this->assertSame(self::LID, Chat::first()->remote_jid);

        $this->record('A2', self::LID, ['remoteJidAlt' => '5595981110695@s.whatsapp.net']);

        $this->assertSame(1, Chat::count());
        $this->assertSame('5595981110695@s.whatsapp.net', Chat::first()->remote_jid);
        $this->assertSame(2, ChatMessage::count());
    }

    public function test_lid_chat_merges_into_the_existing_phone_chat(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->record('P1', '5595981110695@s.whatsapp.net', [], false, 'from phone');
        $this->record('L1', self::LID, [], false, 'from lid');
        $this->assertSame(2, Chat::count());

        $resolver = app(LidResolver::class);
        $resolver->learn($this->instance, self::LID, '5595981110695');

        $this->assertSame(1, Chat::count());
        $chat = Chat::first();
        $this->assertSame('5595981110695@s.whatsapp.net', $chat->remote_jid);
        $this->assertSame(2, ChatMessage::where('chat_id', $chat->id)->count());
        $this->assertSame(2, (int) $chat->unread_count);
        $this->assertNotNull($chat->last_message_id);
    }

    public function test_crm_contact_with_the_lid_resolves_it_without_http(): void
    {
        Http::fake();
        Contact::create(['name' => 'Maria', 'contact' => '5595981110695', 'user_id' => $this->user->id, 'lid' => self::LID]);

        $this->record('A1', self::LID);

        Http::assertNothingSent();
        $chat = Chat::first();
        $this->assertSame('5595981110695@s.whatsapp.net', $chat->remote_jid);
        $this->assertNotNull($chat->contact_id);
    }

    public function test_evolution_messages_are_used_to_find_the_phone(): void
    {
        Http::fake([
            '*findContacts*' => Http::response([]),
            '*findMessages*' => Http::response(['messages' => ['records' => [['key' => ['id' => 'x', 'remoteJid' => self::LID, 'remoteJidAlt' => '5595981110695@s.whatsapp.net']]]]]),
        ]);

        $this->assertSame('5595981110695', app(LidResolver::class)->resolve($this->instance, self::LID));
    }

    public function test_evolution_contact_record_is_used_and_the_miss_is_not_retried(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $resolver = app(LidResolver::class);

        $this->assertNull($resolver->resolve($this->instance, self::LID));
        $count = count(Http::recorded());
        $this->assertNull($resolver->resolve($this->instance, self::LID));
        $this->assertCount($count, Http::recorded());   // negative cache
    }

    public function test_unresolved_lid_chat_never_shows_the_raw_id(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->record('A1', self::LID);
        $chat = Chat::first();
        $chat->name = null;

        $this->assertStringNotContainsString('@lid', $chat->displayName());
    }

    public function test_fix_command_converts_existing_chats(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->record('A1', self::LID);
        Contact::create(['name' => 'Maria', 'contact' => '5595981110695', 'user_id' => $this->user->id, 'lid' => self::LID]);

        $this->artisan('chat:fix-lids --no-http')->assertExitCode(0);

        $this->assertSame('5595981110695@s.whatsapp.net', Chat::first()->remote_jid);
    }

    public function test_chat_list_converts_known_lids_and_queues_the_unknown(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->record('A1', self::LID);
        $this->record('B1', '1122@lid');
        Contact::create(['name' => 'Maria', 'contact' => '5595981110695', 'user_id' => $this->user->id, 'lid' => self::LID]);

        $this->actingAs($this->user)->getJson(route('chat.api.chats', ['instance' => $this->instance->id]))->assertOk();

        $this->assertTrue(Chat::where('remote_jid', '5595981110695@s.whatsapp.net')->exists());
        Queue::assertPushed(ResolveLidChatJob::class, 1);
    }

    // ---- history -------------------------------------------------------------------------------------------------

    public function test_history_import_reads_every_page_newest_chats_first(): void
    {
        config(['chat.history.per_chat' => 120, 'chat.history.page_size' => 50, 'chat.history.max_chats' => 5]);
        $mk = fn ($from, $to) => array_map(fn ($i) => ['key' => ['id' => "M$i", 'remoteJid' => '5595999@s.whatsapp.net', 'fromMe' => false], 'message' => ['conversation' => "m$i"], 'messageTimestamp' => 1000 + $i], range($from, $to));
        Http::fake([
            '*findChats*' => Http::response([['remoteJid' => '5595999@s.whatsapp.net']]),
            '*findMessages*' => Http::sequence()
                ->push(['messages' => ['records' => $mk(1, 50)]])
                ->push(['messages' => ['records' => $mk(51, 100)]])
                ->push(['messages' => ['records' => $mk(101, 110)]]),
        ]);

        (new SyncChatHistoryJob($this->instance->id))->handle(app(\App\Services\Chat\EvolutionChatClient::class), app(ChatMessageRecorder::class));

        $this->assertSame(110, ChatMessage::count());
        $this->assertSame(0, (int) Chat::first()->unread_count);
    }

    public function test_opening_the_chat_for_the_first_time_imports_history_once(): void
    {
        Queue::fake([SyncChatHistoryJob::class, FetchChatAvatarJob::class]);

        $this->actingAs($this->user)->get(route('chat.index'))->assertOk();
        $this->actingAs($this->user)->get(route('chat.index'))->assertOk();

        Queue::assertPushed(SyncChatHistoryJob::class, 1);
    }

    public function test_history_set_webhook_stores_old_messages(): void
    {
        (new \App\Jobs\ProcessEvolutionWebhookJob(['event' => 'messages.set', 'instance' => '5595111', 'data' => ['messages' => [
            ['key' => ['id' => 'H1', 'remoteJid' => '5595999@s.whatsapp.net', 'fromMe' => false], 'message' => ['conversation' => 'velha'], 'messageTimestamp' => 1000],
            ['key' => ['id' => 'H2', 'remoteJid' => '5595999@s.whatsapp.net', 'fromMe' => true], 'message' => ['conversation' => 'resposta'], 'messageTimestamp' => 1001],
        ]]]))->handle();

        $this->assertSame(2, ChatMessage::count());
        $this->assertSame(0, (int) Chat::first()->unread_count);
    }
}
