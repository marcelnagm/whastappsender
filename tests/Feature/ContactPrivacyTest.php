<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Services\Contacts\ContactPrivacy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ContactPrivacyTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Contact $contact;
    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->contact = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana Silva', 'contact' => '5511999990000', 'email' => 'ana@x.com', 'birthday' => '1990-01-02']);
        $this->chat = Chat::forceCreate(['user_id' => $this->owner->id, 'instance_id' => 1, 'contact_id' => $this->contact->id, 'remote_jid' => '5511999990000@s.whatsapp.net', 'phone' => '5511999990000', 'name' => 'Ana', 'last_message_at' => now(), 'last_message_preview' => 'oi segredo']);
        ChatMessage::forceCreate(['chat_id' => $this->chat->id, 'instance_id' => 1, 'user_id' => $this->owner->id, 'from_me' => false, 'type' => 'text', 'body' => 'meu cpf é segredo', 'status' => 'received']);
        Deal::forceCreate(['user_id' => $this->owner->id, 'contact_id' => $this->contact->id, 'title' => 'Plano', 'value' => 99, 'status' => 'open', 'pipeline_id' => 1, 'stage_id' => 1]);
        Activity::create(['user_id' => $this->owner->id, 'contact_id' => $this->contact->id, 'type' => 'call', 'notes' => 'falou da doença', 'occurred_at' => now()]);
    }

    public function test_export_contains_the_data(): void
    {
        $r = $this->actingAs($this->owner)->get(route('contacts.privacy.export', $this->contact->id))->assertOk();
        $json = json_decode($r->streamedContent(), true);
        $this->assertSame('Ana Silva', $json['contact']['name']);
        $this->assertSame('meu cpf é segredo', $json['conversations'][0]['messages'][0]['text']);
        $this->assertSame('Plano', $json['deals'][0]['title']);
    }

    public function test_erase_wipes_identity_and_content_but_keeps_deals_and_blocks_contact(): void
    {
        $this->actingAs($this->owner)->post(route('contacts.privacy.erase', $this->contact->id), [])->assertSessionHasErrors('confirm');
        $this->assertSame('Ana Silva', $this->contact->fresh()->name);

        $this->actingAs($this->owner)->post(route('contacts.privacy.erase', $this->contact->id), ['confirm' => '1'])->assertRedirect();
        $c = $this->contact->fresh();
        $this->assertStringStartsWith('Erased contact', $c->name);
        $this->assertNull($c->email);
        $this->assertNull($c->birthday);
        $this->assertSame(1, (int) $c->ignore_me);
        $this->assertSame('erased', $c->deactivated_reason);
        $this->assertNull(ChatMessage::first()->body);
        $this->assertNull($this->chat->fresh()->phone);
        $this->assertSame('erased-' . $this->chat->id, $this->chat->fresh()->remote_jid);
        $this->assertNull(Activity::first()->notes);
        $this->assertSame('Plano', Deal::first()->title);
        $this->assertSame('revoked', DB::table('consent_logs')->where('contact_id', $c->id)->value('action'));
        $this->assertStringNotContainsString('segredo', json_encode(app(ContactPrivacy::class)->export($c)));
    }

    public function test_other_accounts_cannot_export_or_erase(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->get(route('contacts.privacy.export', $this->contact->id))->assertStatus(404);
        $this->actingAs($other)->post(route('contacts.privacy.erase', $this->contact->id), ['confirm' => '1'])->assertStatus(404);
        $this->assertSame('Ana Silva', $this->contact->fresh()->name);
    }
}
