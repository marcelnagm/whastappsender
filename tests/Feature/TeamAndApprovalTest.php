<?php

namespace Tests\Feature;

use App\Jobs\DispatchCampaignChannelsJob;
use App\Jobs\SendChatMessageJob;
use App\Models\CampaignItem;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsappJob;
use App\Services\Campaigns\CampaignAudience;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class TeamAndApprovalTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
        Queue::fake();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'dono@t.dev', 'username' => 'dono', 'password' => 'secret123'])->fresh();
    }

    private function member(string $role, string $name = null, ?User $owner = null): User
    {
        $name ??= $role;
        $m = User::create(['name' => $name, 'email' => "$name@t.dev", 'username' => "$name@t.dev", 'password' => 'secret123']);
        $m->forceFill(['parent_user_id' => ($owner ?? $this->owner)->id, 'team_role' => $role])->save();

        return $m->fresh();
    }

    private function contact(string $n, int $score, string $status = 'ativo', ?User $u = null, array $o = []): Contact
    {
        $c = Contact::create($o + ['user_id' => ($u ?? $this->owner)->id, 'contact' => $n, 'name' => "C$n", 'status' => $status]);
        DB::table('contacts')->where('id', $c->id)->update(['score' => $score]);

        return $c->fresh();
    }

    private function item(array $o = []): CampaignItem
    {
        return CampaignItem::create($o + ['name' => 'Black Friday', 'text' => 'Oferta!', 'user_id' => $this->owner->id]);
    }

    private function tool(string $name, array $args = [])
    {
        return $this->postJson('/api/v1/tools/call', ['name' => $name, 'arguments' => $args]);
    }

    // ---- roles & isolation ---------------------------------------------------------------------------------

    public function test_member_works_on_the_owners_data_limited_by_role(): void
    {
        $agent = $this->member('agent');
        $mine = $this->contact('5511900000001', 10);
        $other = User::create(['name' => 'Outro', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $this->contact('5511900000002', 10, 'ativo', $other);

        $this->actingAs($agent)->get('/contacts')->assertOk()->assertSee('C5511900000001')->assertDontSee('C5511900000002');

        // everything outside the role is closed
        foreach (['/billing', '/team', '/me/profile', '/admin/users', '/campaigns', '/ai', '/telegram', '/stats'] as $url) {
            $this->actingAs($agent)->get($url)->assertForbidden();
        }
        $this->actingAs($agent)->post('/contacts', ['name' => 'x', 'contact' => '5511911111111'])->assertForbidden();
        $this->actingAs($agent)->get('/contact/clean')->assertForbidden();
        $this->assertSame(1, Contact::where('user_id', $this->owner->id)->count());
    }

    public function test_each_role_reaches_only_its_screens(): void
    {
        $this->actingAs($this->member('analyst'))->get('/stats')->assertOk();
        $this->actingAs($this->member('analyst'))->get('/campaigns')->assertOk();
        $this->actingAs($this->member('analyst'))->post('/campaigns', ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->member('creator'))->get('/campaign-items')->assertOk();
        $this->actingAs($this->member('creator'))->get('/approvals')->assertForbidden();
        $this->actingAs($this->member('approver'))->get('/approvals')->assertOk();
        $this->actingAs($this->member('approver'))->get('/contacts')->assertOk();
        $this->actingAs($this->member('sender'))->get('/approvals')->assertForbidden();
        $this->actingAs($this->member('admin'))->get('/team')->assertOk();
        $this->actingAs($this->member('admin'))->get('/billing')->assertForbidden();
        $this->actingAs($this->owner)->get('/billing')->assertOk();
    }

    public function test_menu_hides_what_the_role_cannot_open(): void
    {
        $page = $this->actingAs($this->member('agent'))->get('/contacts')->assertOk();
        $page->assertDontSee(route('billing.index'))->assertDontSee(route('team.index'))->assertDontSee(route('approvals.index'));
        $page->assertSee(route('chat.index'));

        $this->actingAs($this->owner)->get('/contacts')->assertSee(route('team.index'))->assertSee(route('billing.index'));
    }

    public function test_disabled_member_or_disabled_owner_is_locked_out(): void
    {
        $m = $this->member('agent');
        $m->forceFill(['active' => false])->save();
        $this->actingAs($m->fresh())->get('/contacts')->assertForbidden();

        $m->forceFill(['active' => true])->save();
        $this->owner->forceFill(['active' => false])->save();
        $this->actingAs($m->fresh())->get('/contacts')->assertForbidden();
    }

    public function test_members_cannot_have_members_and_cannot_manage_their_own_access(): void
    {
        $admin = $this->member('admin');
        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/v1/team', ['name' => 'Novo', 'email' => 'novo@t.dev', 'password' => 'secret123', 'role' => 'agent'])->assertCreated();
        $this->assertSame($this->owner->id, (int) User::find($r->json('data.id'))->parent_user_id, 'the new member belongs to the owner, not to the admin member');

        $this->putJson("/api/v1/team/{$admin->id}", ['role' => 'agent'])->assertStatus(422);
        $this->deleteJson("/api/v1/team/{$admin->id}")->assertStatus(422);
        $this->putJson('/api/v1/team/' . $r->json('data.id'), ['role' => 'sender'])->assertOk()->assertJsonPath('data.role', 'sender');
    }

    public function test_team_crud_for_the_owner_via_api_and_tools(): void
    {
        $this->owner->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($this->owner->fresh());

        $this->postJson('/api/v1/team', ['name' => 'X', 'email' => 'bad', 'password' => 'short', 'role' => 'god'])->assertStatus(422);
        $id = $this->tool('add_team_member', ['name' => 'Ana', 'email' => 'ana@t.dev', 'password' => 'secret123', 'role' => 'approver'])->assertCreated()->json('result.data.id');
        $this->assertSame('approver', User::find($id)->team_role);
        $this->assertTrue(\Hash::check('secret123', User::find($id)->password));

        $this->tool('add_team_member', ['name' => 'Ana2', 'email' => 'ana@t.dev', 'password' => 'secret123', 'role' => 'agent'])->assertStatus(422); // email taken
        $this->assertCount(1, $this->getJson('/api/v1/team')->json('data.members'));
        $this->assertArrayHasKey('approver', $this->getJson('/api/v1/team')->json('data.roles'));

        $this->tool('update_team_member', ['member_id' => $id, 'role' => 'agent', 'active' => false])->assertOk();
        $this->assertSame('agent', User::find($id)->team_role);
        $this->assertFalse((bool) User::find($id)->active);
        $this->tool('remove_team_member', ['member_id' => $id])->assertOk();
        $this->assertNull(User::find($id));

        // another owner's member is not reachable
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $foreign = $this->member('agent', 'foreign', $other);
        $this->deleteJson("/api/v1/team/{$foreign->id}")->assertNotFound();
    }

    public function test_plan_limit_on_team_members(): void
    {
        Plan::syncFromConfig();
        config(['billing.enabled' => true]);
        Sanctum::actingAs($this->owner);

        // free plan: team_members = 0 (also when the saved plan row predates the limit)
        $this->postJson('/api/v1/team', ['name' => 'Ana', 'email' => 'ana@t.dev', 'password' => 'secret123', 'role' => 'agent'])->assertStatus(402);
        $this->assertSame(0, User::where('parent_user_id', $this->owner->id)->count());
    }

    public function test_api_and_mcp_follow_the_role(): void
    {
        $agent = $this->member('agent');
        $wa = Instance::create(['user_id' => $this->owner->id, 'name' => 'WA', 'instance_name' => '5511', 'status' => 'connected']);
        Chat::create(['user_id' => $this->owner->id, 'instance_id' => $wa->id, 'remote_jid' => '5511999@s.whatsapp.net', 'name' => 'Bia', 'last_message_at' => now(), 'last_message_preview' => 'oi']);

        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1, 'data');
        $this->tool('list_conversations')->assertOk();
        $this->getJson('/api/v1/campaigns')->assertForbidden();
        $this->getJson('/api/v1/team')->assertForbidden();
        $this->tool('launch_campaign', ['campaign_item_id' => $this->item()->id])->assertForbidden();
        $this->tool('list_team_members')->assertForbidden();
        $this->tool('admin_list_users')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->deleteJson('/api/v1/contacts')->assertForbidden();
    }

    public function test_member_signature_is_their_own(): void
    {
        $agent = $this->member('agent', 'Joana');
        $wa = Instance::create(['user_id' => $this->owner->id, 'name' => 'WA', 'instance_name' => '5511', 'status' => 'connected']);
        $chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $wa->id, 'remote_jid' => '5511999@s.whatsapp.net', 'name' => 'Bia']);
        $this->owner->forceFill(['chat_signature' => 'Dono'])->save();

        $this->actingAs($agent)->putJson('/chat/api/signature', ['name' => 'Joana'])->assertOk();
        $this->assertSame('Joana', $agent->fresh()->chat_signature);
        $this->assertSame('Dono', $this->owner->fresh()->chat_signature, 'the owner signature is untouched');

        $this->actingAs($agent->fresh())->postJson("/chat/api/chats/{$chat->id}/send", ['text' => 'Oi'])->assertCreated();
        $this->assertStringContainsString('Joana', ChatMessage::where('chat_id', $chat->id)->value('body'));
        $this->assertStringNotContainsString('Dono', ChatMessage::where('chat_id', $chat->id)->value('body'));
    }

    // ---- approval ----------------------------------------------------------------------------------------

    public function test_creator_submits_approver_approves_sender_launches_once(): void
    {
        $hot = $this->contact('5511900000010', 90);
        $this->contact('5511900000011', 5);
        $item = $this->item();

        // creator: can submit, cannot approve or launch
        Sanctum::actingAs($this->member('creator'));
        $this->postJson("/api/v1/campaign-items/{$item->id}/submit")->assertOk()->assertJsonPath('data.approval_status', 'pending');
        $this->postJson("/api/v1/campaign-items/{$item->id}/approve", ['tiers' => ['hot'], 'channels' => ['whatsapp']])->assertForbidden();
        $this->postJson("/api/v1/campaign-items/{$item->id}/generate-all")->assertForbidden();

        // approver: chooses the audience; zero reach is refused
        Sanctum::actingAs($this->member('approver'));
        $this->assertCount(1, $this->getJson('/api/v1/approvals')->json('data'));
        $this->postJson("/api/v1/campaign-items/{$item->id}/approve", ['min_score' => 99, 'channels' => ['whatsapp']])->assertStatus(422);
        $r = $this->postJson("/api/v1/campaign-items/{$item->id}/approve", ['tiers' => ['hot'], 'channels' => ['whatsapp'], 'note' => 'ok'])->assertOk();
        $this->assertSame('approved', $r->json('data.approval_status'));
        $this->assertSame(1, $r->json('data.audience_count'));
        $this->postJson("/api/v1/campaign-items/{$item->id}/generate-all")->assertForbidden(); // approving is not sending

        // sender: launches what was approved, once
        $sender = $this->member('sender');
        Sanctum::actingAs($sender);
        $this->postJson("/api/v1/campaign-items/{$item->id}/generate-all")->assertOk()->assertJsonPath('data.contacts_count', 1);
        $this->assertSame('launched', $item->fresh()->approval_status);
        $this->postJson("/api/v1/campaign-items/{$item->id}/generate-all")->assertForbidden();

        // the audience drives the send rows: only the hot contact
        Artisan::call('whatsapp:gerar', ['item_id' => $item->id]);
        $this->assertSame([$hot->id], WhatsappJob::where('campaign_item_id', $item->id)->pluck('contact_id')->all());
    }

    public function test_sender_cannot_launch_unapproved_and_owner_can(): void
    {
        $this->contact('5511900000010', 50);
        $item = $this->item();

        Sanctum::actingAs($this->member('sender'));
        $this->postJson("/api/v1/campaign-items/{$item->id}/generate-all")->assertForbidden();
        $this->tool('launch_campaign', ['campaign_item_id' => $item->id])->assertForbidden();
        $this->actingAs($this->member('sender', 'sender2'), 'web')->get("/campaign-items/{$item->id}/generateAll")->assertSessionHas('error');

        // the owner is the final approver: launches directly, audience unset = everyone (as before)
        $this->owner->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($this->owner->fresh());
        $this->postJson("/api/v1/campaign-items/{$item->id}/generate-all")->assertOk();
        $this->assertNull($item->fresh()->approval_status);
    }

    public function test_changing_the_message_takes_the_approval_away(): void
    {
        $item = $this->item();
        $this->contact('5511900000010', 50);
        app(\App\Services\Campaigns\CampaignApproval::class)->approve($item, $this->owner, ['channels' => ['whatsapp']]);
        $this->assertSame('approved', $item->fresh()->approval_status);

        $item = $item->fresh();
        $item->text = 'Outra coisa totalmente diferente';
        $item->save();

        $this->assertNull($item->fresh()->approval_status);
        $this->assertNull($item->fresh()->approved_by);

        // a change of name only keeps it
        app(\App\Services\Campaigns\CampaignApproval::class)->approve($item->fresh(), $this->owner, ['channels' => ['whatsapp']]);
        $item->fresh()->update(['name' => 'Renomeada']);
        $this->assertSame('approved', $item->fresh()->approval_status);
    }

    public function test_web_approval_screen_preview_approve_and_reject(): void
    {
        $this->contact('5511900000010', 90);
        $this->contact('5511900000011', 20);
        $item = $this->item();
        $approver = $this->member('approver');

        $this->actingAs($this->member('creator'))->post("/campaign-items/{$item->id}/submit")->assertRedirect();
        $this->assertSame('pending', $item->fresh()->approval_status);

        $this->actingAs($approver)->get('/approvals')->assertOk()->assertSee('Black Friday');
        $this->actingAs($approver)->get("/approvals/{$item->id}")->assertOk()->assertSee('Oferta!');
        $this->actingAs($approver)->postJson("/approvals/{$item->id}/preview", ['channels' => ['whatsapp'], 'tiers' => ['hot']])
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('by_channel.whatsapp', 1);
        $this->actingAs($approver)->postJson("/approvals/{$item->id}/preview", ['channels' => ['whatsapp']])->assertJsonPath('total', 2);

        $this->actingAs($approver)->post("/approvals/{$item->id}/approve", ['channels' => ['whatsapp'], 'tiers' => ['warm', 'cold']])->assertRedirect(route('approvals.index'));
        $this->assertSame(1, $item->fresh()->audience_count);
        $this->assertSame(['warm', 'cold'], $item->fresh()->audience['tiers']);

        $this->actingAs($approver)->post("/approvals/{$item->id}/reject", ['note' => 'Texto agressivo'])->assertRedirect();
        $this->assertSame('rejected', $item->fresh()->approval_status);
        $this->assertSame('Texto agressivo', $item->fresh()->approval_note);

        // another account's item is not reachable
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $foreign = CampaignItem::create(['name' => 'F', 'text' => 'x', 'user_id' => $other->id]);
        $this->actingAs($approver)->get("/approvals/{$foreign->id}")->assertNotFound();
    }

    public function test_tools_for_audience_and_approval(): void
    {
        $this->contact('5511900000010', 90);
        $item = $this->item();
        $this->owner->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($this->owner->fresh());

        $r = $this->tool('preview_campaign_audience', ['campaign_item_id' => $item->id, 'tiers' => ['hot'], 'channels' => ['whatsapp', 'telegram'], 'save' => true])->assertOk();
        $this->assertSame(1, $r->json('result.data.total'));
        $this->assertSame(['hot'], $item->fresh()->audience['tiers']);

        $this->tool('submit_campaign_item_for_approval', ['campaign_item_id' => $item->id])->assertOk();
        $this->assertSame(1, count($this->tool('list_pending_approvals')->json('result.data')));
        $this->tool('approve_campaign_item', ['campaign_item_id' => $item->id])->assertOk(); // keeps the saved audience
        $this->assertSame('approved', $item->fresh()->approval_status);
        $this->tool('reject_campaign_item', ['campaign_item_id' => $item->id, 'note' => 'no'])->assertOk();
        $this->assertSame('rejected', $item->fresh()->approval_status);
    }

    // ---- audience -----------------------------------------------------------------------------------------

    public function test_audience_by_score_tiers_range_and_channel_windows(): void
    {
        $a = new CampaignAudience();
        $uid = $this->owner->id;
        $hot = $this->contact('5511900000001', 85);
        $warm = $this->contact('5511900000002', 50);
        $this->contact('5511900000003', 5);
        $this->contact('5511900000004', 90, 'inativo');
        $this->contact('5511900000005', 90, 'ativo', null, ['ignore_me' => 1]);

        $count = fn ($aud) => $a->count($uid, $aud)['total'];
        $this->assertSame(3, $count(['channels' => ['whatsapp']]));
        $this->assertSame(1, $count(['tiers' => ['hot']]));
        $this->assertSame(2, $count(['tiers' => ['hot', 'warm']]));
        $this->assertSame(1, $count(['tiers' => ['frozen']]));
        $this->assertSame(1, $count(['min_score' => 40, 'max_score' => 60]));
        $this->assertSame(1, $count(['min_score' => 100, 'max_score' => 60])); // a reversed range is normalised (60..100 = the hot one)
        $this->assertSame(['whatsapp'], CampaignAudience::normalize(['channels' => ['nope']])['channels']);
        $this->assertSame(['hot'], CampaignAudience::normalize(['tiers' => ['hot', 'x']])['tiers']);

        // Telegram: reachable once the person wrote; Instagram: only inside the 24h window; TikTok: nobody wrote
        $tgBot = Instance::create(['user_id' => $uid, 'name' => 'bot', 'instance_name' => 'tg-1', 'status' => 'connected', 'channel' => 'telegram']);
        $igAcc = Instance::create(['user_id' => $uid, 'name' => 'ig', 'instance_name' => 'ig-1', 'status' => 'connected', 'channel' => 'instagram']);
        $tg = $this->contact('tg:1', 60, 'telegram');
        $igIn = $this->contact('ig:1', 60, 'instagram');
        $igOut = $this->contact('ig:2', 60, 'instagram');
        $this->contact('tg:2', 60, 'telegram'); // never opened a chat
        foreach ([[$tgBot, $tg, now()->subDays(30)], [$igAcc, $igIn, now()->subHours(2)], [$igAcc, $igOut, now()->subHours(30)]] as [$inst, $c, $when]) {
            $chat = Chat::create(['user_id' => $uid, 'instance_id' => $inst->id, 'remote_jid' => $c->contact, 'contact_id' => $c->id]);
            ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $inst->id, 'user_id' => $uid, 'message_id' => 'm' . $c->id, 'from_me' => false, 'type' => 'text', 'body' => 'oi', 'status' => 'received', 'message_timestamp' => $when]);
        }

        $r = $a->count($uid, ['channels' => ['telegram', 'instagram', 'tiktok']]);
        $this->assertSame(['telegram' => 1, 'instagram' => 1, 'tiktok' => 0], $r['by_channel']);
        $this->assertSame(2, $r['total']);
        $this->assertSame(0, $a->count($uid, ['channels' => ['instagram'], 'tiers' => ['hot']])['total']);
        $this->assertSame(1, $a->count($uid, ['channels' => ['instagram'], 'tiers' => ['warm']])['total']);

        // the same person is reached once: a Telegram contact linked to a WhatsApp contact in the audience is left out
        $tg->update(['linked_contact_id' => $hot->id]);
        $this->assertSame(0, $a->count($uid, ['channels' => ['whatsapp', 'telegram'], 'tiers' => ['hot', 'warm']])['by_channel']['telegram']);
        $this->assertSame(1, $a->count($uid, ['channels' => ['telegram']])['by_channel']['telegram']);
        $this->assertTrue(CampaignAudience::usesOtherChannels(['channels' => ['whatsapp', 'tiktok']]));
        $this->assertFalse(CampaignAudience::usesOtherChannels(null));
    }

    public function test_campaign_reaches_channel_contacts_inside_their_window_and_counts_the_quota(): void
    {
        $uid = $this->owner->id;
        $bot = Instance::create(['user_id' => $uid, 'name' => 'bot', 'instance_name' => 'tg-1', 'status' => 'connected', 'channel' => 'telegram']);
        $ig = Instance::create(['user_id' => $uid, 'name' => 'ig', 'instance_name' => 'ig-1', 'status' => 'connected', 'channel' => 'instagram']);
        $tg = $this->contact('tg:1', 60, 'telegram');
        $old = $this->contact('ig:9', 60, 'instagram');
        foreach ([[$bot, $tg, now()->subDay()], [$ig, $old, now()->subDays(3)]] as [$inst, $c, $when]) {
            $chat = Chat::create(['user_id' => $uid, 'instance_id' => $inst->id, 'remote_jid' => $c->contact, 'contact_id' => $c->id]);
            ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $inst->id, 'user_id' => $uid, 'message_id' => 'x' . $c->id, 'from_me' => false, 'type' => 'text', 'body' => 'oi', 'status' => 'received', 'message_timestamp' => $when]);
        }
        $item = $this->item();
        $item->forceFill(['audience' => ['channels' => ['telegram', 'instagram']]])->save(); // approval fields are never mass assignable

        (new DispatchCampaignChannelsJob($item->id))->handle(app(CampaignAudience::class), app(\App\Services\Billing\Entitlements::class), app(\App\Services\Chat\ChatMessageRecorder::class));

        $sent = ChatMessage::where('from_me', true)->get();
        $this->assertCount(1, $sent, 'Instagram is outside its 24h window, so only Telegram is sent');
        $this->assertSame('Oferta!', $sent[0]->body);
        $this->assertSame($bot->id, (int) $sent[0]->instance_id, 'goes through the channel the person used');
        Queue::assertPushed(SendChatMessageJob::class, 1);
        $this->assertSame(1, (int) DB::table('usage_counters')->where('user_id', $uid)->where('metric', 'messages')->value('used'));
    }
}
