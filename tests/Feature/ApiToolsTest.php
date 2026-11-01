<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\Api\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** REST endpoints and MCP tools for channels, conversations, ranking and the unresponsive-contact check. */
class ApiToolsTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        config(['telegram.api_url' => 'https://tg.test', 'app.url' => 'https://crm.test', 'ai.allow_private_hosts' => false,
            'tiktok.api_url' => 'https://tt.test/open', 'tiktok.app_id' => 'appid', 'tiktok.app_secret' => 'appsecret']);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x', 'role' => 'admin']);
        Sanctum::actingAs($this->user->fresh());
    }

    private function tool(string $name, array $args = [])
    {
        return $this->postJson('/api/v1/tools/call', ['name' => $name, 'arguments' => $args]);
    }

    private function bot(): Instance
    {
        return Instance::create(['user_id' => $this->user->id, 'name' => 'Bot', 'instance_name' => 'tg-1', 'status' => 'connected', 'channel' => 'telegram',
            'telegram_token' => '111111:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'telegram_username' => 'loja_bot', 'telegram_secret' => 'sekret']);
    }

    private function chat(Instance $i, string $jid = 'tg:1', ?Contact $c = null, array $o = []): Chat
    {
        return Chat::create($o + ['user_id' => $i->user_id, 'instance_id' => $i->id, 'remote_jid' => $jid, 'name' => 'Ana', 'contact_id' => $c?->id,
            'last_message_at' => now(), 'last_message_preview' => 'oi', 'unread_count' => 2]);
    }

    public function test_every_registered_tool_has_a_handler_and_new_ones_exist(): void
    {
        $names = collect(ToolRegistry::definitions(true))->pluck('function.name')->all();
        $this->assertSame([], array_values(array_diff($names, array_keys(ToolRegistry::handlers()))));
        foreach (['get_contact_ranking', 'rescore_contact', 'get_contact_channels', 'link_contacts', 'unlink_contact', 'check_unresponsive_contacts',
            'list_channels', 'connect_telegram_bot', 'connect_instagram_account', 'get_tiktok_connect_url', 'renew_channel_connection', 'remove_channel_account',
            'get_channel_stats', 'list_conversations', 'get_conversation_messages', 'send_conversation_message', 'mark_conversation_read', 'pause_conversation_ai'] as $t) {
            $this->assertContains($t, $names, $t);
        }
        $this->getJson('/api/v1/tools')->assertOk();
    }

    public function test_channel_accounts_through_rest_and_tools(): void
    {
        Http::fake([
            'tg.test/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'loja_bot', 'first_name' => 'Loja']]),
            'tg.test/bot*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            'tg.test/bot*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $token = '123456:AAHabcdefghijklmnopqrstuvwxyz012345';

        $this->postJson('/api/v1/channels/telegram', ['token' => 'nope'])->assertStatus(422);
        $r = $this->postJson('/api/v1/channels/telegram', ['token' => $token])->assertCreated();
        $id = $r->json('data.id');
        $this->assertStringNotContainsString($token, $r->getContent());
        $this->assertSame('telegram', $r->json('data.channel'));

        $this->getJson('/api/v1/channels?channel=telegram')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/channels?channel=instagram')->assertOk()->assertJsonCount(0, 'data');
        $this->tool('list_channels', ['channel' => 'telegram'])->assertOk()->assertJsonPath('result.data.0.id', $id);

        $this->tool('renew_channel_connection', ['instance_id' => $id])->assertOk();
        $this->getJson('/api/v1/channels/stats?days=7')->assertOk()->assertJsonPath('data.days', 7);
        $this->tool('get_channel_stats', ['days' => 30])->assertOk()->assertJsonPath('result.data.days', 30);

        $url = $this->getJson('/api/v1/channels/tiktok/url')->assertOk()->json('data.url');
        $this->assertStringContainsString('appid', $url);
        $this->tool('get_tiktok_connect_url')->assertOk()->assertJsonStructure(['result' => ['data' => ['url']]]);

        // WhatsApp endpoints refuse channel accounts
        $this->getJson("/api/v1/instances/{$id}")->assertStatus(422);
        $this->postJson("/api/v1/instances/{$id}/sync")->assertStatus(422);

        $this->tool('remove_channel_account', ['instance_id' => $id])->assertOk();
        $this->assertNull(Instance::find($id));
    }

    public function test_wa_instance_cannot_be_renewed_as_channel(): void
    {
        $wa = Instance::create(['user_id' => $this->user->id, 'name' => 'WA', 'instance_name' => '5511', 'status' => 'connected']);
        $this->postJson("/api/v1/channels/{$wa->id}/renew")->assertStatus(422);
        $this->deleteJson("/api/v1/channels/{$wa->id}")->assertStatus(422);
        $this->assertNotNull(Instance::find($wa->id));
    }

    public function test_conversations_list_filter_and_tools(): void
    {
        $bot = $this->bot();
        $wa = Instance::create(['user_id' => $this->user->id, 'name' => 'WA', 'instance_name' => '5511', 'status' => 'connected']);
        $a = $this->chat($bot, 'tg:1');
        $b = $this->chat($wa, '5511988887777@s.whatsapp.net', null, ['name' => 'Bia', 'unread_count' => 0]);

        $r = $this->getJson('/api/v1/conversations')->assertOk();
        $this->assertCount(2, $r->json('data'));
        $r = $this->getJson('/api/v1/conversations?channel=telegram')->assertOk();
        $this->assertSame([$a->id], collect($r->json('data'))->pluck('id')->all());
        $this->assertTrue($r->json('data.0.window_open'));
        $this->assertSame([$b->id], collect($this->getJson('/api/v1/conversations?channel=whatsapp')->json('data'))->pluck('id')->all());
        $this->assertSame([$a->id], collect($this->getJson('/api/v1/conversations?unread=1')->json('data'))->pluck('id')->all());
        $this->assertSame([$b->id], collect($this->tool('list_conversations', ['search' => 'Bia'])->json('result.data'))->pluck('id')->all());

        // other users' chats are hidden
        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $oi = Instance::create(['user_id' => $other->id, 'name' => 'B', 'instance_name' => 'tg-2', 'status' => 'connected', 'channel' => 'telegram']);
        $foreign = $this->chat($oi, 'tg:9');
        $this->assertCount(2, $this->getJson('/api/v1/conversations')->json('data'));
        $this->getJson("/api/v1/conversations/{$foreign->id}/messages")->assertStatus(403);
        $this->tool('get_conversation_messages', ['chat_id' => $foreign->id])->assertStatus(404);
        $this->tool('send_conversation_message', ['chat_id' => $foreign->id, 'text' => 'oi'])->assertStatus(404);
    }

    public function test_messages_send_read_and_ai_pause(): void
    {
        Queue::fake();
        $bot = $this->bot();
        $contact = Contact::create(['user_id' => $this->user->id, 'contact' => 'tg:1', 'name' => 'Ana', 'status' => 'telegram']);
        $chat = $this->chat($bot, 'tg:1', $contact);
        $first = ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $bot->id, 'user_id' => $this->user->id, 'message_id' => 'm1', 'from_me' => false, 'type' => 'text', 'body' => 'olá', 'status' => 'received', 'message_timestamp' => now()->subMinute()]);
        $second = ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $bot->id, 'user_id' => $this->user->id, 'message_id' => 'm2', 'from_me' => false, 'type' => 'text', 'body' => 'tudo bem?', 'status' => 'received', 'message_timestamp' => now()]);

        $r = $this->getJson("/api/v1/conversations/{$chat->id}/messages")->assertOk();
        $this->assertCount(2, $r->json('data.messages'));
        $older = $this->tool('get_conversation_messages', ['chat_id' => $chat->id, 'before_id' => $second->id])->assertOk();
        $this->assertSame([$first->id], collect($older->json('result.data.messages'))->pluck('id')->all());

        $this->postJson("/api/v1/conversations/{$chat->id}/messages", [])->assertStatus(422);
        $this->postJson("/api/v1/conversations/{$chat->id}/messages", ['text' => 'Oi Ana'])->assertCreated()->assertJsonPath('data.message.body', 'Oi Ana');
        $this->tool('send_conversation_message', ['chat_id' => $chat->id, 'text' => 'Segunda'])->assertCreated();
        $this->assertSame(2, ChatMessage::where('chat_id', $chat->id)->where('from_me', true)->count());
        Queue::assertPushed(\App\Jobs\SendChatMessageJob::class, 2);

        $this->tool('mark_conversation_read', ['chat_id' => $chat->id])->assertOk();
        $this->assertSame(0, (int) $chat->fresh()->unread_count);

        $this->tool('pause_conversation_ai', ['chat_id' => $chat->id, 'paused' => true])->assertOk()->assertJsonPath('result.data.paused', true);
        $this->postJson("/api/v1/conversations/{$chat->id}/ai-pause", ['paused' => false])->assertOk()->assertJsonPath('data.paused', false);
    }

    public function test_instagram_window_is_reported_closed(): void
    {
        $ig = Instance::create(['user_id' => $this->user->id, 'name' => 'IG', 'instance_name' => 'ig-1', 'status' => 'connected', 'channel' => 'instagram']);
        $c = $this->chat($ig, 'ig:5');
        ChatMessage::create(['chat_id' => $c->id, 'instance_id' => $ig->id, 'user_id' => $this->user->id, 'message_id' => 'i1', 'from_me' => false, 'type' => 'text', 'body' => 'oi', 'status' => 'received', 'message_timestamp' => now()->subDays(3)]);
        $c->update(['last_inbound_at' => now()->subDays(3)]);

        $this->assertFalse($this->getJson('/api/v1/conversations?channel=instagram')->json('data.0.window_open'));
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['text' => 'oi'])->assertStatus(422);
    }

    public function test_contact_channels_link_unlink_and_filters(): void
    {
        $wa = Contact::create(['user_id' => $this->user->id, 'contact' => '5511999990000', 'name' => 'Ana', 'status' => 'ativo']);
        $tg = Contact::create(['user_id' => $this->user->id, 'contact' => 'tg:1', 'name' => 'Ana TG', 'status' => 'telegram']);
        $wa2 = Contact::create(['user_id' => $this->user->id, 'contact' => '5511999990001', 'name' => 'Outra', 'status' => 'ativo']);

        $this->tool('link_contacts', ['contact_id' => $tg->id, 'linked_contact_id' => $wa->id])->assertOk();
        $this->assertSame($wa->id, (int) $tg->fresh()->linked_contact_id);
        $this->postJson("/api/v1/contacts/{$wa->id}/link", ['linked_contact_id' => $wa2->id])->assertStatus(422); // two WhatsApp contacts

        $r = $this->tool('get_contact_channels', ['contact_id' => $wa->id])->assertOk();
        $this->assertEqualsCanonicalizing([$wa->id, $tg->id], collect($r->json('result.data.contacts'))->pluck('id')->all());
        $this->assertSame($wa->id, $this->getJson("/api/v1/contacts/{$tg->id}/channels")->json('data.root_id'));

        $this->tool('unlink_contact', ['contact_id' => $tg->id])->assertOk();
        $this->assertNull($tg->fresh()->linked_contact_id);

        DB::table('contacts')->where('id', $wa->id)->update(['score' => 80]);
        DB::table('contacts')->where('id', $wa2->id)->update(['score' => 5]);
        $this->assertSame(['Ana'], collect($this->tool('list_contacts', ['tier' => 'hot'])->json('result.data'))->pluck('name')->all());
        $this->assertSame('Ana', $this->tool('list_contacts', ['sort' => 'score', 'status' => 'ativo'])->json('result.data.0.name'));
        $this->assertSame('Ana', $this->tool('get_contact_ranking', ['limit' => 1])->json('result.data.0.name'));
        $this->tool('rescore_contact', ['contact_id' => $wa->id])->assertOk();
        $this->tool('update_contact', ['contact_id' => $wa->id, 'name' => 'Ana', 'contact' => '5511999990000', 'score' => 42])->assertOk();
        $this->assertTrue($wa->fresh()->score_locked);
    }

    public function test_unresponsive_check_dry_run_scoped_to_user_and_manual_reactivation(): void
    {
        config(['contacts.undelivered' => array_merge(config('contacts.undelivered'), ['enabled' => true, 'after_hours' => 24, 'min_messages' => 1])]);
        $wa = Instance::create(['user_id' => $this->user->id, 'name' => 'WA', 'instance_name' => '5511', 'status' => 'connected']);
        $mine = Contact::create(['user_id' => $this->user->id, 'contact' => '5511900000001', 'name' => 'Mudo', 'status' => 'ativo']);
        $healthy = Contact::create(['user_id' => $this->user->id, 'contact' => '5511900000002', 'name' => 'Vivo', 'status' => 'ativo']);
        $other = User::create(['name' => 'o', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $theirs = Contact::create(['user_id' => $other->id, 'contact' => '5511900000003', 'name' => 'Alheio', 'status' => 'ativo']);

        $old = now()->subDays(3)->toDateTimeString();
        $n = 0;
        $job = function (Contact $c, string $st) use (&$n, $old) {
            DB::table('whatsapp_job')->insert(['endpoint' => 'x', 'status' => 'processado', 'message_id' => 'J' . (++$n), 'evolution_status' => $st, 'user_id' => $c->user_id, 'contact_id' => $c->id, 'created_at' => $old, 'updated_at' => $old]);
        };
        $job($mine, 'sent');
        $job($theirs, 'sent');
        $job($healthy, 'READ'); // proves receipts do arrive for this account

        $dry = $this->tool('check_unresponsive_contacts', ['dry_run' => true])->assertOk();
        $this->assertSame([$mine->id], $dry->json('result.data.deactivated'));
        $this->assertSame('ativo', $mine->fresh()->status);

        $this->postJson('/api/v1/contacts/check-unresponsive', [])->assertOk();
        $this->assertSame('inativo', $mine->fresh()->status);
        $this->assertSame('undelivered', $mine->fresh()->deactivated_reason);
        $this->assertSame('ativo', $theirs->fresh()->status, 'other accounts are not touched');

        // a person puts it back: the automatic reason is cleared
        $this->putJson("/api/v1/contacts/{$mine->id}", ['name' => 'Mudo', 'contact' => '5511900000001', 'status' => 'ativo'])->assertOk();
        $this->assertNull($mine->fresh()->deactivated_reason);
        $this->assertNull($mine->fresh()->deactivated_at);
    }
}
