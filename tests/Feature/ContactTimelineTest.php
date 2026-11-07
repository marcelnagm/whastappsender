<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatNote;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Instance;
use App\Models\User;
use App\Services\Contacts\ContactTimeline;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ContactTimelineTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->contact = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5595999990000']);
    }

    public function test_timeline_merges_sources_newest_first(): void
    {
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'contact_id' => $this->contact->id, 'remote_jid' => 'x@s.whatsapp.net']);
        ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $inst->id, 'user_id' => $this->owner->id, 'message_id' => 'a', 'from_me' => false, 'type' => 'text', 'body' => 'Quero um orçamento', 'message_timestamp' => now()->subHours(3)]);
        ChatNote::create(['user_id' => $this->owner->id, 'chat_id' => $chat->id, 'author_id' => $this->owner->id, 'body' => 'Cliente quente']);

        $t = app(ContactTimeline::class)->for($this->contact);
        $kinds = $t->pluck('kind')->all();
        $this->assertContains('message', $kinds);
        $this->assertContains('note', $kinds);
        $this->assertContains('contact', $kinds);
        $this->assertSame($t->sortByDesc(fn ($x) => $x['at']->timestamp)->values()->all(), $t->all());
    }

    public function test_show_renders_timeline_and_hides_other_accounts_contacts(): void
    {
        $this->actingAs($this->owner)->get(route('contacts.show', $this->contact->id))->assertOk()->assertSee(__('Timeline'));

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->get(route('contacts.show', $this->contact->id))->assertNotFound();
        $this->actingAs($other)->get(route('contacts.edit', $this->contact->id))->assertNotFound();
        $this->actingAs($other)->delete(route('contacts.destroy', $this->contact->id))->assertNotFound();
        $this->assertNotNull(Contact::find($this->contact->id));
    }

    public function test_update_cannot_change_the_owner(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($this->owner)->put(route('contacts.update', $this->contact->id), ['name' => 'Ana B', 'contact' => '5595999990000', 'user_id' => $other->id]);
        $this->assertSame($this->owner->id, (int) $this->contact->fresh()->user_id);
        $this->actingAs($other)->put(route('contacts.update', $this->contact->id), ['name' => 'Hack', 'contact' => '5595999990000'])->assertNotFound();
        $this->assertNotSame('Hack', $this->contact->fresh()->name);
    }
}
