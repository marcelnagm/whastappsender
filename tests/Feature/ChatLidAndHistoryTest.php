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

    // ---- visible progress of the import ------------------------------------------------------------------

    public function test_sync_button_returns_a_queued_status_and_does_not_queue_twice(): void
    {
        Queue::fake([SyncChatHistoryJob::class, FetchChatAvatarJob::class]);

        $first = $this->actingAs($this->user)->postJson(route('chat.api.sync', $this->instance->id))->assertStatus(202)->json();
        $this->assertSame('queued', $first['state']);
        $this->actingAs($this->user)->postJson(route('chat.api.sync', $this->instance->id));

        Queue::assertPushed(SyncChatHistoryJob::class, 1);
        $this->actingAs($this->user)->getJson(route('chat.api.sync-status', $this->instance->id))->assertJson(['state' => 'queued']);
    }

    public function test_import_reports_progress_completion_and_notifies_the_user(): void
    {
        Http::fake([
            '*findChats*' => Http::response([['remoteJid' => '5595999@s.whatsapp.net'], ['remoteJid' => '120363@g.us']]),
            '*findMessages*' => Http::response(['messages' => ['records' => [
                ['key' => ['id' => 'M1', 'remoteJid' => '5595999@s.whatsapp.net', 'fromMe' => false], 'message' => ['conversation' => 'a'], 'messageTimestamp' => 1000],
            ]]]),
        ]);
        \App\Services\Chat\HistorySyncStatus::queued($this->instance->id);

        (new SyncChatHistoryJob($this->instance->id))->handle(app(\App\Services\Chat\EvolutionChatClient::class), app(ChatMessageRecorder::class));

        $st = \App\Services\Chat\HistorySyncStatus::get($this->instance->id);
        $this->assertSame('done', $st['state']);
        $this->assertSame(1, $st['chats_done']);
        $this->assertSame(1, $st['chats_total']);
        $this->assertSame(1, $st['messages']);
        $this->assertSame('Chat history imported', $this->user->notifications()->first()->data['title']);
    }

    public function test_import_failure_is_reported_instead_of_silent(): void
    {
        Http::fake(['*findChats*' => Http::response('boom', 500)]);

        (new SyncChatHistoryJob($this->instance->id))->handle(app(\App\Services\Chat\EvolutionChatClient::class), app(ChatMessageRecorder::class));

        $st = \App\Services\Chat\HistorySyncStatus::get($this->instance->id);
        $this->assertSame('failed', $st['state']);
        $this->assertStringContainsString('boom', $st['error']);
        $this->assertSame('Chat history import failed', $this->user->notifications()->first()->data['title']);
    }

    public function test_an_import_that_never_started_is_reported_as_stalled(): void
    {
        \App\Services\Chat\HistorySyncStatus::queued($this->instance->id);
        $this->travel(20)->minutes();

        $this->assertSame('failed', \App\Services\Chat\HistorySyncStatus::get($this->instance->id)['state']);
        $this->assertFalse(\App\Services\Chat\HistorySyncStatus::isBusy($this->instance->id));
    }

    public function test_other_users_cannot_read_the_import_status(): void
    {
        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);

        $this->actingAs($other)->getJson(route('chat.api.sync-status', $this->instance->id))->assertForbidden();
    }

    public function test_when_evolution_has_no_chat_list_conversations_are_rebuilt_from_stored_messages(): void
    {
        $rec = fn ($id, $jid, $ts) => ['key' => ['id' => $id, 'remoteJid' => $jid, 'fromMe' => false], 'message' => ['conversation' => $id], 'messageTimestamp' => $ts];
        Http::fake([
            '*findChats*' => Http::response([]),
            '*findMessages*' => Http::sequence()
                ->push(['messages' => ['records' => array_merge(
                    array_map(fn ($i) => $rec("A$i", '5595111@s.whatsapp.net', 1000 + $i), range(1, 30)),
                    array_map(fn ($i) => $rec("B$i", '5595222@s.whatsapp.net', 2000 + $i), range(1, 15)),
                    [$rec('G1', '120363@g.us', 3000)]
                )]])
                ->push(['messages' => ['records' => []]]),
        ]);
        config(['chat.history.page_size' => 50]);

        (new SyncChatHistoryJob($this->instance->id))->handle(app(\App\Services\Chat\EvolutionChatClient::class), app(ChatMessageRecorder::class));

        $st = \App\Services\Chat\HistorySyncStatus::get($this->instance->id);
        $this->assertSame('done', $st['state']);
        $this->assertSame('messages', $st['source']);
        $this->assertSame(2, Chat::count());          // the group is out of scope
        $this->assertSame(45, ChatMessage::count());
        $this->assertSame(2, $st['chats_done']);
    }

    // ---- merging, persistence and instance choice ----------------------------------------------------------

    public function test_learned_pair_is_stored_for_good_and_used_by_later_messages(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        app(LidResolver::class)->learn($this->instance, self::LID, '5595981110695', 'manual');
        $this->assertSame('5595981110695', \App\Models\LidMapping::where('lid', self::LID)->value('phone'));

        Cache::flush();                                  // cache gone: the table still knows
        $this->record('A1', self::LID);                  // no remoteJidAlt, no contact, Evolution knows nothing

        $this->assertSame(1, Chat::count());
        $this->assertSame('5595981110695@s.whatsapp.net', Chat::first()->remote_jid);
    }

    public function test_echo_of_a_message_we_sent_teaches_the_lid(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $chat = Chat::create(['user_id' => $this->user->id, 'instance_id' => $this->instance->id, 'remote_jid' => '5595981110695@s.whatsapp.net', 'phone' => '5595981110695']);
        ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $this->instance->id, 'user_id' => $this->user->id, 'message_id' => 'SENT1', 'from_me' => true, 'type' => 'text', 'body' => 'oi', 'status' => 'sent', 'message_timestamp' => now()]);

        $this->record('SENT1', self::LID, [], false, 'oi', true);       // Evolution echoes it under the lid

        $this->assertSame(1, Chat::count());
        $this->assertSame('5595981110695', \App\Models\LidMapping::where('lid', self::LID)->value('phone'));
        $this->record('R1', self::LID, [], false, 'resposta');           // the customer's reply now lands in the same chat
        $this->assertSame(1, Chat::count());
        $this->assertSame(3 - 1, ChatMessage::count());
    }

    public function test_echo_of_a_campaign_send_teaches_the_lid(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $contact = Contact::create(['name' => 'Maria', 'contact' => '5595981110695', 'user_id' => $this->user->id]);
        \App\Models\WhatsappJob::create(['user_id' => $this->user->id, 'contact_id' => $contact->id, 'endpoint' => 'x', 'status' => 'processado', 'tentativas' => 1, 'payload' => [], 'message_id' => 'CAMP1']);

        $this->record('CAMP1', self::LID, [], false, 'promo', true);

        $this->assertSame('5595981110695@s.whatsapp.net', Chat::first()->remote_jid);
    }

    public function test_lids_are_merged_per_instance_and_never_across_instances(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $other = Instance::create(['user_id' => $this->user->id, 'name' => 'o', 'instance_name' => '5595222', 'status' => 'connected']);
        $this->record('A1', self::LID);
        app(ChatMessageRecorder::class)->recordUpsert('5595222', ['key' => ['id' => 'B1', 'remoteJid' => self::LID, 'fromMe' => false], 'message' => ['conversation' => 'x'], 'messageTimestamp' => now()->timestamp]);
        $this->assertSame(2, Chat::count());

        app(LidResolver::class)->learn($this->instance, self::LID, '5595981110695');

        $this->assertSame(2, Chat::count());   // one chat per instance, both converted, none merged into the other
        $this->assertSame([$this->instance->id, $other->id], Chat::orderBy('instance_id')->pluck('instance_id')->map(fn ($i) => (int) $i)->sort()->values()->all());
        $this->assertSame(0, Chat::where('remote_jid', 'like', '%@lid')->count());
    }

    public function test_agent_can_link_an_unidentified_chat_to_a_phone_and_it_merges(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->record('P1', '5595981110695@s.whatsapp.net', [], false, 'from phone');
        $this->record('L1', self::LID, [], false, 'from lid');
        $lidChat = Chat::where('remote_jid', self::LID)->first();

        $this->actingAs($this->user)->postJson(route('chat.api.link-phone', $lidChat->id), ['phone' => '+55 (95) 98111-0695'])->assertOk()
            ->assertJsonPath('chat.phone', '5595981110695')->assertJsonPath('chat.unidentified', false);

        $this->assertSame(1, Chat::count());
        $this->assertSame(2, ChatMessage::count());
        $this->actingAs($this->user)->postJson(route('chat.api.link-phone', Chat::first()->id), ['phone' => '5595'])->assertStatus(422);
    }

    public function test_link_phone_is_private_and_validated(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $this->record('L1', self::LID);
        $chat = Chat::first();
        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);

        $this->actingAs($other)->postJson(route('chat.api.link-phone', $chat->id), ['phone' => '5595981110695'])->assertForbidden();
        $this->actingAs($this->user)->postJson(route('chat.api.link-phone', $chat->id), ['phone' => 'abc'])->assertStatus(422);
    }

    public function test_message_is_sent_through_the_instance_of_the_chat_that_is_open(): void
    {
        Queue::fake([FetchChatAvatarJob::class]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'WA1']])]);
        $second = Instance::create(['user_id' => $this->user->id, 'name' => 'b', 'instance_name' => '5595333', 'status' => 'connected']);
        $chat = Chat::create(['user_id' => $this->user->id, 'instance_id' => $second->id, 'remote_jid' => '5595981110695@s.whatsapp.net', 'phone' => '5595981110695']);

        $this->actingAs($this->user)->postJson(route('chat.api.send', $chat->id), ['text' => 'ola', 'client_id' => 'c1'])->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/5595333'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/5595111'));
    }

    public function test_history_can_be_imported_for_any_of_the_users_instances(): void
    {
        Queue::fake([SyncChatHistoryJob::class, FetchChatAvatarJob::class]);
        $second = Instance::create(['user_id' => $this->user->id, 'name' => 'b', 'instance_name' => '5595333', 'status' => 'connected']);

        $this->actingAs($this->user)->postJson(route('chat.api.sync', $second->id))->assertStatus(202);

        Queue::assertPushed(SyncChatHistoryJob::class, fn ($j) => $j->instanceId === $second->id);
        $this->actingAs($this->user)->getJson(route('chat.api.sync-status', $second->id))->assertJson(['state' => 'queued']);
        $this->actingAs($this->user)->getJson(route('chat.api.sync-status', $this->instance->id))->assertJson(['state' => 'idle']);
    }
}
