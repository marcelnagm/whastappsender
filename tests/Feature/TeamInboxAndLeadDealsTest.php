<?php

namespace Tests\Feature;

use App\Jobs\FetchChatAvatarJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatNote;
use App\Models\ChatTag;
use App\Models\ContactForm;
use App\Models\ContactFormSubmission;
use App\Models\Deal;
use App\Models\Instance;
use App\Models\User;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Forms\ContactFormFields;
use App\Services\Forms\FormSubmissionProcessor;
use App\Services\Pipeline\SalesPipeline;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Team inbox (assignment, labels, internal notes) and deals opened from conversations and forms. */
class TeamInboxAndLeadDealsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $bia;
    private User $caio;
    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Storage::fake('local');
        Cache::flush();
        Queue::fake([FetchChatAvatarJob::class]);
        config(['forms.default_country_code' => '55']);

        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->bia = $this->member('bia', TeamRoles::AGENT);
        $this->caio = $this->member('caio', TeamRoles::AGENT);
        $this->instance = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => '5595111', 'status' => 'connected']);
    }

    private function member(string $name, string $role): User
    {
        $m = User::create(['name' => $name, 'email' => "$name@t.dev", 'username' => "$name@t.dev", 'password' => 'secret123']);
        $m->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => $role])->save();

        return $m->fresh();
    }

    private function settings(array $s): void
    {
        $this->owner->forceFill($s)->save();
    }

    private function inbound(string $id, string $phone, ?int $at = null, string $text = 'Olá'): ChatMessage
    {
        return app(ChatMessageRecorder::class)->recordUpsert('5595111', [
            'key' => ['id' => $id, 'remoteJid' => $phone . '@s.whatsapp.net', 'fromMe' => false],
            'pushName' => 'Cliente ' . $phone,
            'message' => ['conversation' => $text],
            'messageTimestamp' => $at ?? now()->timestamp,
        ]);
    }

    private function chatOf(string $phone): Chat
    {
        return Chat::where('phone', $phone)->firstOrFail();
    }

    // ---- assignment ----------------------------------------------------------------------------------------------------

    public function test_manual_mode_leaves_new_conversations_unassigned(): void
    {
        $this->inbound('a1', '5595900000001');
        $this->assertNull($this->chatOf('5595900000001')->assigned_to);
    }

    public function test_balanced_mode_spreads_new_conversations_among_members_not_the_owner(): void
    {
        $this->settings(['chat_assign_mode' => 'balanced']);
        foreach (['01', '02', '03', '04', '05'] as $n) {
            $this->inbound("a$n", "55959000000$n");
        }
        $counts = Chat::selectRaw('assigned_to, count(*) n')->groupBy('assigned_to')->pluck('n', 'assigned_to')->all();
        $this->assertEqualsCanonicalizing([$this->bia->id, $this->caio->id], array_keys($counts));
        $this->assertEqualsCanonicalizing([3, 2], array_values($counts));
        $this->assertArrayNotHasKey($this->owner->id, $counts);

        // a new message in an assigned conversation keeps its attendant
        $first = $this->chatOf('5595900000001')->assigned_to;
        $this->inbound('again', '5595900000001');
        $this->assertSame($first, $this->chatOf('5595900000001')->assigned_to);
    }

    public function test_balanced_mode_prefers_the_member_with_fewer_open_conversations(): void
    {
        $this->settings(['chat_assign_mode' => 'balanced']);
        $this->inbound('a1', '5595900000001');
        $this->inbound('a2', '5595900000002');
        $first = $this->chatOf('5595900000001')->assigned_to;
        $other = $this->chatOf('5595900000002')->assigned_to;
        $this->assertNotSame($first, $other);

        $this->chatOf('5595900000001')->forceFill(['archived' => true])->save(); // the first attendant closed theirs
        $this->inbound('a3', '5595900000003');
        $this->assertSame($first, $this->chatOf('5595900000003')->assigned_to); // 0 open vs 1 open
    }

    public function test_imported_history_neither_assigns_nor_opens_deals(): void
    {
        $this->settings(['chat_assign_mode' => 'balanced', 'deal_auto_chat' => true]);
        $this->inbound('old', '5595900000009', now()->subDays(3)->timestamp);
        $this->assertNull($this->chatOf('5595900000009')->assigned_to);
        $this->assertSame(0, Deal::count());
    }

    public function test_owner_assigns_by_hand_but_only_to_people_of_the_account(): void
    {
        $this->inbound('a1', '5595900000001');
        $chat = $this->chatOf('5595900000001');
        $stranger = User::create(['name' => 'X', 'email' => 'x@t.dev', 'username' => 'x', 'password' => 'x']);

        $this->actingAs($this->owner)->postJson("/chat/api/chats/{$chat->id}/assign", ['user_id' => $this->bia->id])->assertOk();
        $this->assertSame($this->bia->id, $chat->fresh()->assigned_to);
        $this->actingAs($this->owner)->postJson("/chat/api/chats/{$chat->id}/assign", ['user_id' => $stranger->id])->assertStatus(422);
        $this->actingAs($this->owner)->postJson("/chat/api/chats/{$chat->id}/assign", ['user_id' => null])->assertOk();
        $this->assertNull($chat->fresh()->assigned_to);
    }

    public function test_chat_list_filters_by_assignee_and_label(): void
    {
        $this->inbound('a1', '5595900000001');
        $this->inbound('a2', '5595900000002');
        $mine = $this->chatOf('5595900000001');
        $mine->forceFill(['assigned_to' => $this->bia->id])->save();
        $tag = ChatTag::create(['user_id' => $this->owner->id, 'name' => 'VIP', 'color' => '#ff0000']);
        $this->chatOf('5595900000002')->tags()->attach($tag->id);

        $list = fn ($q) => collect($this->actingAs($this->bia)->getJson("/chat/api/chats?instance={$this->instance->id}$q")->assertOk()->json('chats'));
        $this->assertCount(2, $list(''));
        $this->assertSame([$mine->id], $list('&filter=mine')->pluck('id')->all());
        $this->assertCount(1, $list('&filter=unassigned'));
        $this->assertSame('VIP', $list('&tag=' . $tag->id)->first()['tags'][0]['name']);
        $this->assertSame('bia', $list('&filter=mine')->first()['assigned']['name']);
    }

    public function test_attendants_can_be_limited_to_their_own_and_unassigned_conversations(): void
    {
        $this->settings(['chat_agents_see' => 'assigned']);
        $this->inbound('a1', '5595900000001');
        $this->inbound('a2', '5595900000002');
        $this->inbound('a3', '5595900000003');
        $this->chatOf('5595900000001')->forceFill(['assigned_to' => $this->bia->id])->save();
        $this->chatOf('5595900000002')->forceFill(['assigned_to' => $this->caio->id])->save();

        $ids = collect($this->actingAs($this->bia)->getJson("/chat/api/chats?instance={$this->instance->id}")->json('chats'))->pluck('phone')->sort()->values()->all();
        $this->assertSame(['5595900000001', '5595900000003'], $ids);

        $this->actingAs($this->bia)->getJson('/chat/api/chats/' . $this->chatOf('5595900000002')->id . '/messages')->assertForbidden();
        $this->actingAs($this->bia)->getJson('/chat/api/chats/' . $this->chatOf('5595900000001')->id . '/messages')->assertOk();
        $this->actingAs($this->owner)->getJson('/chat/api/chats/' . $this->chatOf('5595900000002')->id . '/messages')->assertOk();

        $this->settings(['chat_agents_see' => 'all']);
        $this->actingAs($this->bia)->getJson('/chat/api/chats/' . $this->chatOf('5595900000002')->id . '/messages')->assertOk();
    }

    // ---- labels and notes ----------------------------------------------------------------------------------------------

    public function test_labels_are_created_attached_and_removed(): void
    {
        $this->inbound('a1', '5595900000001');
        $chat = $this->chatOf('5595900000001');

        $tag = $this->actingAs($this->bia)->postJson('/chat/api/tags', ['name' => '  Orçamento ', 'color' => '#00aa00'])->assertOk()->json();
        $same = $this->actingAs($this->bia)->postJson('/chat/api/tags', ['name' => 'Orçamento'])->json();
        $this->assertSame($tag['id'], $same['id']); // no duplicates
        $this->actingAs($this->bia)->postJson('/chat/api/tags', ['name' => 'x', 'color' => 'red'])->assertStatus(422);

        $this->actingAs($this->bia)->putJson("/chat/api/chats/{$chat->id}/tags", ['tag_ids' => [$tag['id'], 999]])->assertOk();
        $this->assertSame(['Orçamento'], $chat->fresh()->tags->pluck('name')->all()); // unknown ids ignored

        $this->actingAs($this->bia)->deleteJson('/chat/api/tags/' . $tag['id'])->assertForbidden(); // only owner / administrator
        $this->actingAs($this->owner)->deleteJson('/chat/api/tags/' . $tag['id'])->assertOk();
        $this->assertSame(0, $chat->fresh()->tags()->count());
    }

    public function test_internal_notes_are_team_only_and_have_an_author(): void
    {
        $this->inbound('a1', '5595900000001');
        $chat = $this->chatOf('5595900000001');

        $this->actingAs($this->bia)->postJson("/chat/api/chats/{$chat->id}/notes", ['body' => 'Cliente quer desconto'])->assertOk();
        $note = ChatNote::first();
        $this->assertSame($this->bia->id, (int) $note->author_id);
        $this->assertSame(1, ChatMessage::count()); // a note is not a message and never goes out

        $crm = $this->actingAs($this->caio)->getJson("/chat/api/chats/{$chat->id}/crm")->assertOk()->json();
        $this->assertSame('Cliente quer desconto', $crm['notes'][0]['body']);
        $this->assertSame('bia', $crm['notes'][0]['author']);
        $this->assertFalse($crm['notes'][0]['mine']);
        $this->assertNull($crm['settings']); // attendants do not see the account settings

        $this->actingAs($this->caio)->deleteJson('/chat/api/notes/' . $note->id)->assertForbidden();
        $this->actingAs($this->bia)->deleteJson('/chat/api/notes/' . $note->id)->assertOk();
        $this->assertSame(0, ChatNote::count());
    }

    public function test_inbox_settings_are_for_owner_and_administrator_only(): void
    {
        $body = ['assign_mode' => 'balanced', 'agents_see' => 'assigned', 'deal_auto_chat' => true, 'deal_auto_form' => true];
        $this->actingAs($this->bia)->putJson('/chat/api/settings', $body)->assertForbidden();
        $this->assertSame('manual', $this->owner->fresh()->chat_assign_mode);

        $this->actingAs($this->owner)->putJson('/chat/api/settings', $body)->assertOk();
        $o = $this->owner->fresh();
        $this->assertSame(['balanced', 'assigned', true, true], [$o->chat_assign_mode, $o->chat_agents_see, (bool) $o->deal_auto_chat, (bool) $o->deal_auto_form]);
        $this->actingAs($this->owner)->putJson('/chat/api/settings', ['assign_mode' => 'x'] + $body)->assertStatus(422);

        $admin = $this->member('adm', TeamRoles::ADMIN);
        $this->actingAs($admin)->putJson('/chat/api/settings', ['assign_mode' => 'manual'] + $body)->assertOk();
        $this->assertSame('manual', $this->owner->fresh()->chat_assign_mode);
    }

    // ---- deals from conversations --------------------------------------------------------------------------------------

    public function test_nothing_is_opened_unless_the_account_turns_it_on(): void
    {
        $this->inbound('a1', '5595900000001');
        $this->assertSame(0, Deal::count());
    }

    public function test_a_new_customer_opens_one_deal_in_the_first_stage_with_the_attendant(): void
    {
        $this->settings(['deal_auto_chat' => true, 'chat_assign_mode' => 'balanced']);
        $this->inbound('a1', '5595900000001');
        $this->inbound('a2', '5595900000001', null, 'ainda aí?');

        $deal = Deal::sole();
        $chat = $this->chatOf('5595900000001');
        $this->assertSame('chat', $deal->source);
        $this->assertSame($chat->id, (int) $deal->chat_id);
        $this->assertSame($chat->assigned_to, (int) $deal->assigned_to);
        $this->assertSame('open', $deal->status);
        $this->assertSame('Cliente 5595900000001', $deal->title);
        $this->assertSame('New lead', $deal->stage->name);
    }

    public function test_a_finished_deal_is_not_reopened_by_an_ongoing_conversation_but_a_returning_customer_is_a_new_lead(): void
    {
        $this->settings(['deal_auto_chat' => true]);
        $this->inbound('a1', '5595900000001');
        $deal = Deal::sole();
        $svc = app(SalesPipeline::class);
        $svc->move($deal, $deal->stage->pipeline_id ? \App\Models\PipelineStage::where('pipeline_id', $deal->pipeline_id)->where('kind', 'won')->firstOrFail() : null);

        $this->inbound('a2', '5595900000001'); // writes again the same day
        $this->assertSame(1, Deal::count());

        ChatMessage::query()->update(['created_at' => now()->subDays(40)]); // silent for 40 days
        $this->inbound('a3', '5595900000001');
        $this->assertSame(2, Deal::count());
        $this->assertSame(1, Deal::where('status', 'open')->count());
    }

    public function test_button_in_the_chat_creates_a_deal_once(): void
    {
        $this->inbound('a1', '5595900000001');
        $chat = $this->chatOf('5595900000001');

        $this->assertNull($this->actingAs($this->bia)->getJson("/chat/api/chats/{$chat->id}/crm")->json('deal'));
        $this->actingAs($this->bia)->postJson("/chat/api/chats/{$chat->id}/deal")->assertOk();
        $this->assertSame(1, Deal::count());
        $this->actingAs($this->bia)->postJson("/chat/api/chats/{$chat->id}/deal")->assertStatus(422);
        $this->assertSame('New lead', $this->actingAs($this->bia)->getJson("/chat/api/chats/{$chat->id}/crm")->json('deal.stage'));
    }

    // ---- deals from forms ------------------------------------------------------------------------------------------------

    private function submit(): ContactFormSubmission
    {
        $form = ContactForm::create(['user_id' => $this->owner->id, 'name' => 'Site', 'fields' => ContactFormFields::normalize(ContactFormFields::defaults())]);
        $s = ContactFormSubmission::create(['contact_form_id' => $form->id, 'user_id' => $this->owner->id, 'status' => 'pending',
            'data' => ['name' => 'Ana', 'phone' => '5595981110000', 'email' => 'ana@x.com', 'message' => 'Quero um orçamento']]);
        app(FormSubmissionProcessor::class)->process($s->fresh());

        return $s->fresh();
    }

    public function test_a_form_opens_a_deal_when_the_account_wants_it(): void
    {
        $this->settings(['deal_auto_form' => true]);
        $s = $this->submit();

        $deal = Deal::sole();
        $this->assertSame('form', $deal->source);
        $this->assertStringContainsString('Site', $deal->title);
        $this->assertSame($s->contact_id, (int) $deal->contact_id);
    }

    public function test_form_and_chat_together_do_not_duplicate_the_deal(): void
    {
        $this->settings(['deal_auto_form' => true, 'deal_auto_chat' => true]);
        $this->submit();
        $this->submit(); // the same person again while the deal is open
        $this->assertSame(1, Deal::count());
    }

    public function test_a_form_opens_no_deal_by_default(): void
    {
        $this->submit();
        $this->assertSame(0, Deal::count());
    }
}
