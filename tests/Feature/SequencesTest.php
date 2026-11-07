<?php

namespace Tests\Feature;

use App\Jobs\SendChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\User;
use App\Services\Chat\Sequences;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Follow-up sequences that stop when the customer answers. */
class SequencesTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Chat $chat;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        Queue::fake();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $this->contact = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana Souza', 'contact' => '5595999990000']);
        $this->chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'contact_id' => $this->contact->id, 'remote_jid' => '5595999990000@s.whatsapp.net', 'name' => 'Ana']);
    }

    private function seq(): Sequence
    {
        return app(Sequences::class)->save($this->owner, null, 'Pos-proposta', true, [
            ['delay_minutes' => 60, 'body' => 'Oi {{first_name}}, viu a proposta?'],
            ['delay_minutes' => 1440, 'body' => 'Posso ajudar com algo?'],
        ]);
    }

    private function due(SequenceEnrollment $e): void
    {
        SequenceEnrollment::whereKey($e->id)->update(['next_at' => now()->subMinute()]);
    }

    public function test_steps_go_out_in_order_and_sequence_completes(): void
    {
        $e = app(Sequences::class)->enroll($this->chat, $this->seq(), $this->owner);
        $this->assertSame(0, app(Sequences::class)->dispatchDue());

        $this->due($e);
        $this->assertSame(1, app(Sequences::class)->dispatchDue());
        $this->assertSame('Oi Ana, viu a proposta?', ChatMessage::where('from_me', true)->first()->body);
        $this->assertSame(2, $e->fresh()->next_position);
        $this->assertSame('active', $e->fresh()->status);

        $this->due($e);
        app(Sequences::class)->dispatchDue();
        $this->assertSame('completed', $e->fresh()->status);
        Queue::assertPushed(SendChatMessageJob::class, 2);
    }

    public function test_customer_reply_stops_the_sequence(): void
    {
        $e = app(Sequences::class)->enroll($this->chat, $this->seq(), $this->owner);
        ChatMessage::create(['chat_id' => $this->chat->id, 'instance_id' => $this->chat->instance_id, 'user_id' => $this->owner->id, 'message_id' => 'in1', 'from_me' => false, 'type' => 'text', 'body' => 'Vi sim!', 'message_timestamp' => now()]);

        $this->assertSame('stopped', $e->fresh()->status);
        $this->assertSame('replied', $e->fresh()->stop_reason);
        $this->due($e);
        $this->assertSame(0, app(Sequences::class)->dispatchDue());
    }

    public function test_opt_out_and_closed_window_stop_it(): void
    {
        $e = app(Sequences::class)->enroll($this->chat, $this->seq(), $this->owner);
        $this->contact->forceFill(['consent_status' => 'revoked'])->save();
        $this->due($e);
        $this->assertSame(0, app(Sequences::class)->dispatchDue());
        $this->assertSame('opt_out', $e->fresh()->stop_reason);
    }

    public function test_cannot_enroll_twice_or_in_a_paused_sequence(): void
    {
        $seq = $this->seq();
        app(Sequences::class)->enroll($this->chat, $seq, $this->owner);
        $this->actingAs($this->owner)->postJson(route('chat.api.sequence.enroll', $this->chat->id), ['sequence_id' => $seq->id])->assertStatus(422);

        $seq->update(['active' => false]);
        $this->actingAs($this->owner)->postJson(route('chat.api.sequence.enroll', $this->chat->id), ['sequence_id' => $seq->id])->assertStatus(422);
    }

    public function test_enroll_and_stop_from_the_chat_and_crm_payload(): void
    {
        $seq = $this->seq();
        $r = $this->actingAs($this->owner)->postJson(route('chat.api.sequence.enroll', $this->chat->id), ['sequence_id' => $seq->id])->assertCreated();
        $id = $r->json('sequences.running.0.id');
        $this->actingAs($this->owner)->getJson(route('chat.api.crm', $this->chat->id))->assertJsonPath('sequences.running.0.name', 'Pos-proposta');
        $this->actingAs($this->owner)->deleteJson(route('chat.api.sequence.stop', [$this->chat->id, $id]))->assertOk();
        $this->assertSame('manual', SequenceEnrollment::find($id)->stop_reason);
    }

    public function test_management_page_crud_and_isolation(): void
    {
        $this->actingAs($this->owner)->get(route('sequences.index'))->assertOk();
        $this->actingAs($this->owner)->post(route('sequences.store'), ['name' => 'Nova', 'active' => 1, 'steps' => [['delay_minutes' => 30, 'body' => 'Oi'], ['delay_minutes' => 5, 'body' => '']]])->assertRedirect();
        $s = Sequence::where('name', 'Nova')->first();
        $this->assertSame(1, $s->steps()->count()); // empty step dropped
        $this->actingAs($this->owner)->post(route('sequences.store'), ['name' => 'Vazia', 'steps' => [['delay_minutes' => 30, 'body' => '']]])->assertStatus(422);

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->put(route('sequences.update', $s->id), ['name' => 'x', 'steps' => [['delay_minutes' => 1, 'body' => 'y']]])->assertNotFound();
        $this->actingAs($this->owner)->delete(route('sequences.destroy', $s->id))->assertRedirect();
        $this->assertNull(Sequence::find($s->id));
    }

    public function test_deleting_a_sequence_stops_running_enrollments(): void
    {
        $e = app(Sequences::class)->enroll($this->chat, $this->seq(), $this->owner);
        $this->actingAs($this->owner)->delete(route('sequences.destroy', $e->sequence_id));
        $this->assertSame('removed', $e->fresh()->stop_reason);
    }
}
