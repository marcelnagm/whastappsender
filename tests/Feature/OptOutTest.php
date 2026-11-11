<?php

namespace Tests\Feature;

use App\Models\CampaignItem;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\Consent\Consent;
use App\Services\Consent\OptOut;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class OptOutTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Casa Verde', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->contact = Contact::forceCreate(['user_id' => $this->owner->id, 'name' => 'Marina', 'contact' => '5511999990001', 'consent_status' => 'granted']);
    }

    public function test_natural_phrases_and_noise_opt_out(): void
    {
        foreach (['PARAR!', '  Stop. ', '#sair', 'Descadastrar', 'Quero sair da lista', 'não quero mais receber', 'Unsubscribe', 'Darme de baja'] as $t) {
            $this->assertSame('optout', Consent::keyword($t), $t);
        }
        foreach (['Posso parar na loja hoje?', 'Quero sair mais cedo', 'stop by tomorrow', 'obrigado'] as $t) {
            $this->assertNull(Consent::keyword($t), $t);
        }
        $this->assertSame('optin', Consent::keyword('/start'));
    }

    public function test_the_link_is_signed_and_opening_it_changes_nothing(): void
    {
        $url = OptOut::url($this->contact);
        $this->get($url)->assertOk()->assertSee('Casa Verde')->assertSee(__('Yes, stop messages'));
        $this->assertSame('granted', $this->contact->fresh()->consent_status);

        $this->get(route('optout.show', ['contact' => $this->contact->id, 'signature' => 'forged']))->assertNotFound();
        $this->post(route('optout.confirm', ['contact' => $this->contact->id, 'signature' => 'forged']))->assertNotFound();
        $this->assertSame('granted', $this->contact->fresh()->consent_status);
    }

    public function test_confirming_opts_out_with_proof_and_can_be_undone(): void
    {
        $sig = OptOut::signature($this->contact->id);
        $this->post(route('optout.confirm', ['contact' => $this->contact->id, 'signature' => $sig]), ['reason' => 'too many messages'])->assertOk();
        $c = $this->contact->fresh();
        $this->assertSame('revoked', $c->consent_status);
        $this->assertSame(1, (int) $c->ignore_me);
        $log = \DB::table('consent_logs')->where('contact_id', $c->id)->where('action', 'revoked')->first();
        $this->assertSame('link', $log->source);
        $this->assertStringContainsString('too many messages', $log->evidence);

        $this->get(OptOut::url($c))->assertOk()->assertSee(__('I changed my mind: send me messages again'));
        $this->post(route('optout.undo', ['contact' => $c->id, 'signature' => $sig]))->assertOk();
        $this->assertSame('granted', $c->fresh()->consent_status);
        $this->assertNull($c->fresh()->ignore_me);
    }

    public function test_campaign_messages_carry_the_footer_only_when_the_account_asks(): void
    {
        $item = CampaignItem::forceCreate(['name' => 'Promo', 'text' => 'Olá, tem promoção!', 'user_id' => $this->owner->id]);
        $this->assertSame('Olá, tem promoção!', $item->generate($this->contact->id)['text']);

        $this->owner->forceFill(['optout_mode' => 'link'])->save();
        $text = $item->generate($this->contact->id)['text'];
        $this->assertStringStartsWith("Olá, tem promoção!\n\n", $text);
        $this->assertStringContainsString(OptOut::url($this->contact), $text);

        $this->owner->forceFill(['optout_mode' => 'keyword'])->save();
        $this->assertStringContainsString('STOP', $item->generate($this->contact->id)['text']);
        $this->assertStringNotContainsString('/optout/', $item->generate($this->contact->id)['text']);

        $this->owner->forceFill(['optout_mode' => 'both', 'optout_text' => 'Chega? Mande {word} ou {link}'])->save();
        $text = $item->generate($this->contact->id)['text'];
        $this->assertStringContainsString('Chega? Mande STOP ou ' . OptOut::url($this->contact), $text);
    }

    public function test_option_is_saved_from_the_consent_page(): void
    {
        $this->actingAs($this->owner)->get(route('consent.index'))->assertOk()->assertSee(__('Let customers stop by themselves'));
        $this->post(route('consent.optout'), ['optout_mode' => 'both', 'optout_text' => 'Pare: {link}'])->assertRedirect();
        $this->assertSame('both', $this->owner->fresh()->optout_mode);
        $this->assertSame('Pare: {link}', $this->owner->fresh()->optout_text);
        $this->post(route('consent.optout'), ['optout_mode' => 'bogus'])->assertSessionHasErrors('optout_mode');
    }

    public function test_agent_records_an_opt_out_from_the_chat_and_undoes_it(): void
    {
        $inst = Instance::forceCreate(['user_id' => $this->owner->id, 'name' => 'i', 'instance_name' => 'i', 'status' => 'connected']);
        $chat = Chat::forceCreate(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'remote_jid' => '5511999990001@s.whatsapp.net', 'phone' => '5511999990001', 'contact_id' => $this->contact->id, 'last_message_at' => now()]);

        $this->actingAs($this->owner)->getJson(route('chat.api.crm', $chat))->assertOk()->assertJsonPath('consent.out', false);
        $this->postJson(route('chat.api.optout', $chat))->assertOk()->assertJson(['out' => true]);
        $this->assertSame('revoked', $this->contact->fresh()->consent_status);
        $this->getJson(route('chat.api.crm', $chat))->assertJsonPath('consent.out', true)->assertJsonPath('consent.url', OptOut::url($this->contact));
        $this->postJson(route('chat.api.optout', $chat), ['in' => true])->assertOk()->assertJson(['out' => false]);
        $this->assertSame('granted', $this->contact->fresh()->consent_status);
    }

    public function test_footer_is_written_in_the_language_of_the_account(): void
    {
        $this->owner->forceFill(['optout_mode' => 'both', 'locale' => 'pt_BR'])->save();
        $f = app(OptOut::class)->footer($this->owner->fresh(), $this->contact);
        $this->assertStringContainsString('Para parar de receber nossas mensagens, responda PARAR', $f);
        $this->assertSame('en', app()->getLocale());   // the worker's own language is put back
    }

    private function chatFor(): array
    {
        $inst = Instance::forceCreate(['user_id' => $this->owner->id, 'name' => 'i', 'instance_name' => 'i', 'status' => 'connected']);
        $chat = Chat::forceCreate(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'remote_jid' => '5511999990001@s.whatsapp.net', 'phone' => '5511999990001', 'contact_id' => $this->contact->id, 'last_message_at' => now(), 'last_message_from_me' => false]);

        return [$inst, $chat];
    }

    public function test_opting_out_by_writing_is_confirmed_once_in_the_same_conversation(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$inst, $chat] = $this->chatFor();
        $svc = app(Consent::class);

        $this->assertSame('revoked', $svc->handleKeyword($this->contact, 'PARAR!', $inst));
        $m = \App\Models\ChatMessage::where('chat_id', $chat->id)->where('from_me', true)->first();
        $this->assertNotNull($m);
        $this->assertSame('bot', $m->source);
        $this->assertStringContainsString('Casa Verde', $m->body);
        $this->assertStringContainsString(OptOut::url($this->contact), $m->body);
        $this->assertStringContainsString('#ENTRAR', $m->body);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendChatMessageJob::class, 1);
        $this->assertTrue($chat->fresh()->last_message_from_me);

        // writing it again changes nothing, so nothing is answered
        $svc->handleKeyword($this->contact->fresh(), 'stop', $inst);
        $this->assertSame(1, \App\Models\ChatMessage::where('chat_id', $chat->id)->where('from_me', true)->count());

        // coming back is confirmed too
        $svc->handleKeyword($this->contact->fresh(), '/start', $inst);
        $last = \App\Models\ChatMessage::where('chat_id', $chat->id)->where('from_me', true)->orderByDesc('id')->first();
        $this->assertStringContainsString('will receive messages from Casa Verde again', $last->body);
    }

    public function test_confirmation_can_be_turned_off_or_rewritten(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$inst, $chat] = $this->chatFor();
        $this->owner->forceFill(['optout_confirm' => false])->save();
        app(Consent::class)->handleKeyword($this->contact, 'stop', $inst);
        $this->assertSame(0, \App\Models\ChatMessage::where('chat_id', $chat->id)->where('from_me', true)->count());

        app(Consent::class)->grant($this->contact->fresh(), 'manual');
        $this->owner->forceFill(['optout_confirm' => true, 'optout_confirm_text' => 'Tchau de {company}! Volte com {optin}'])->save();
        app(Consent::class)->handleKeyword($this->contact->fresh(), 'stop', $inst);
        $this->assertSame('Tchau de Casa Verde! Volte com #ENTRAR', \App\Models\ChatMessage::where('chat_id', $chat->id)->where('from_me', true)->first()->body);
    }

    public function test_confirmation_settings_are_saved(): void
    {
        $this->actingAs($this->owner)->post(route('consent.optout'), ['optout_mode' => 'off', 'optout_confirm' => '1', 'optout_confirm_text' => 'Ok {company}'])->assertRedirect();
        $this->assertTrue((bool) $this->owner->fresh()->optout_confirm);
        $this->assertSame('Ok {company}', $this->owner->fresh()->optout_confirm_text);
        $this->post(route('consent.optout'), ['optout_mode' => 'off'])->assertRedirect();
        $this->assertFalse((bool) $this->owner->fresh()->optout_confirm);
    }
}
