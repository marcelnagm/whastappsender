<?php

namespace Tests\Feature;

use App\Jobs\SendChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\CsatSetting;
use App\Models\CsatSurvey;
use App\Models\Instance;
use App\Models\User;
use App\Services\Chat\Csat;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Closing a conversation and the CSAT/NPS question that follows. */
class CsatTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        Queue::fake();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5595999990000']);
        $this->chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'contact_id' => $c->id, 'remote_jid' => '5595999990000@s.whatsapp.net', 'name' => 'Ana']);
        $this->in('Oi, preciso de ajuda');
    }

    private function in(string $body): ChatMessage
    {
        $m = ChatMessage::create(['chat_id' => $this->chat->id, 'instance_id' => $this->chat->instance_id, 'user_id' => $this->owner->id, 'message_id' => uniqid(), 'from_me' => false,
            'type' => 'text', 'body' => $body, 'message_timestamp' => now()]);
        $this->chat->forceFill(['last_message_from_me' => false, 'last_message_at' => now()])->save();

        return $m;
    }

    private function on(string $scale = 'csat'): void
    {
        CsatSetting::create(['user_id' => $this->owner->id, 'enabled' => true, 'scale' => $scale, 'expire_hours' => 24]);
    }

    public function test_closing_sends_the_question_when_enabled(): void
    {
        $this->on();
        $this->actingAs($this->owner)->postJson(route('chat.api.close', $this->chat->id))->assertOk()->assertJson(['closed' => true, 'survey' => true]);

        $this->assertNotNull($this->chat->fresh()->closed_at);
        $this->assertSame(1, CsatSurvey::count());
        $this->assertSame('survey', ChatMessage::where('from_me', true)->first()->source);
        Queue::assertPushed(SendChatMessageJob::class);
    }

    public function test_no_question_when_disabled_or_nothing_received(): void
    {
        $this->actingAs($this->owner)->postJson(route('chat.api.close', $this->chat->id))->assertJson(['survey' => false]);
        $this->assertSame(0, CsatSurvey::count());

        $this->on();
        ChatMessage::where('chat_id', $this->chat->id)->delete();
        $this->chat->forceFill(['closed_at' => null])->save();
        $this->actingAs($this->owner)->postJson(route('chat.api.close', $this->chat->id))->assertJson(['survey' => false]);
    }

    public function test_numeric_reply_is_the_answer_and_does_not_reopen(): void
    {
        $this->on();
        app(Csat::class)->close($this->chat, $this->owner);
        $this->in('5');

        $s = CsatSurvey::first();
        $this->assertSame('answered', $s->status);
        $this->assertSame(5, $s->score);
        $this->assertNotNull($this->chat->fresh()->closed_at);
        $this->assertSame(2, ChatMessage::where('from_me', true)->count()); // question + thanks
    }

    public function test_out_of_range_or_text_is_a_normal_message_that_reopens(): void
    {
        $this->on();
        app(Csat::class)->close($this->chat, $this->owner);
        $this->in('9');
        $this->assertSame('sent', CsatSurvey::first()->status);
        $this->assertNull($this->chat->fresh()->closed_at);
    }

    public function test_expired_survey_does_not_capture(): void
    {
        $this->on();
        app(Csat::class)->close($this->chat, $this->owner);
        CsatSurvey::query()->update(['sent_at' => now()->subHours(30)]);
        $this->in('4');
        $this->assertSame('expired', CsatSurvey::first()->status);
    }

    public function test_nps_scale_and_stats(): void
    {
        $this->on('nps');
        foreach ([10, 9, 6, 8] as $score) {
            CsatSurvey::create(['user_id' => $this->owner->id, 'chat_id' => $this->chat->id, 'scale' => 'nps', 'status' => 'answered', 'score' => $score, 'sent_at' => now(), 'answered_at' => now()]);
        }
        $st = Csat::stats($this->owner->id, 'nps');
        $this->assertSame(4, $st['responses']);
        $this->assertSame(25, $st['nps']); // 2 promoters - 1 detractor of 4
        $this->assertSame(8.25, $st['average']);
    }

    public function test_reopen_and_settings_page(): void
    {
        $this->chat->forceFill(['closed_at' => now()])->save();
        $this->actingAs($this->owner)->postJson(route('chat.api.reopen', $this->chat->id))->assertOk();
        $this->assertNull($this->chat->fresh()->closed_at);

        $this->actingAs($this->owner)->get(route('csat.index'))->assertOk();
        $this->actingAs($this->owner)->put(route('csat.update'), ['enabled' => 1, 'scale' => 'nps', 'expire_hours' => 12])->assertRedirect();
        $this->assertSame('nps', CsatSetting::for($this->owner->id)->scale);
        $this->actingAs($this->owner)->put(route('csat.update'), ['scale' => 'x', 'expire_hours' => 12])->assertSessionHasErrors('scale');
    }

    public function test_other_account_cannot_close(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->postJson(route('chat.api.close', $this->chat->id))->assertForbidden();
    }
}
