<?php

namespace Tests\Feature;

use App\Jobs\FetchChatAvatarJob;
use App\Jobs\SendChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Instance;
use App\Models\User;
use App\Services\Chat\ChatSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ChatSignatureTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;
    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
        Storage::fake('local');
        config(['services.whatsapp' => ['protocol' => 'http', 'url' => 'evo.test', 'port' => 8080, 'apikey' => 'k']]);
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x']);
        $instance = Instance::create(['user_id' => $this->user->id, 'name' => 'n', 'instance_name' => '5595111', 'status' => 'connected']);
        $this->chat = Chat::create(['user_id' => $this->user->id, 'instance_id' => $instance->id, 'remote_jid' => '5595981110695@s.whatsapp.net', 'phone' => '5595981110695']);
    }

    public function test_normalize_rejects_empty_and_blank_names_and_strips_formatting(): void
    {
        $this->assertNull(ChatSignature::normalize(null));
        $this->assertNull(ChatSignature::normalize(''));
        $this->assertNull(ChatSignature::normalize("   \t "));
        $this->assertNull(ChatSignature::normalize('***'));
        $this->assertSame('Ana Paula', ChatSignature::normalize("  *Ana*\n  Paula  "));
        $this->assertSame(40, mb_strlen(ChatSignature::normalize(str_repeat('a', 100))));
    }

    public function test_text_gets_the_signature_header(): void
    {
        $this->assertSame("*Ana (CRM):*\nOlá!", ChatSignature::apply('Ana', 'Olá!'));
        $this->assertSame('Olá!', ChatSignature::apply('  ', 'Olá!'));
        $this->assertNull(ChatSignature::apply('Ana', ''));
        $this->assertSame('*Ana (CRM):*', ChatSignature::apply('Ana', '', true));
    }

    public function test_user_saves_and_clears_the_signature(): void
    {
        $this->actingAs($this->user)->putJson(route('chat.api.signature'), ['name' => '  Carlos  '])
            ->assertOk()->assertJson(['name' => 'Carlos', 'header' => '*Carlos (CRM):*']);
        $this->assertSame('Carlos', $this->user->fresh()->chat_signature);

        $this->actingAs($this->user)->putJson(route('chat.api.signature'), ['name' => '   '])->assertOk()->assertJson(['name' => null]);
        $this->assertNull($this->user->fresh()->chat_signature);
    }

    public function test_message_sent_from_the_crm_carries_the_signature_to_whatsapp(): void
    {
        Queue::fake([FetchChatAvatarJob::class]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'WA1']])]);
        $this->user->forceFill(['chat_signature' => 'Ana'])->save();

        $this->actingAs($this->user)->postJson(route('chat.api.send', $this->chat->id), ['text' => 'Bom dia', 'client_id' => 'c1'])->assertCreated();

        $this->assertSame("*Ana (CRM):*\nBom dia", ChatMessage::first()->body);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/') && $r['text'] === "*Ana (CRM):*\nBom dia");
    }

    public function test_without_signature_the_text_is_untouched(): void
    {
        Queue::fake([FetchChatAvatarJob::class]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'WA1']])]);

        $this->actingAs($this->user)->postJson(route('chat.api.send', $this->chat->id), ['text' => 'Bom dia', 'client_id' => 'c1'])->assertCreated();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/') && $r['text'] === 'Bom dia');
    }

    public function test_media_caption_is_signed_even_without_text_but_voice_notes_are_not(): void
    {
        Queue::fake([FetchChatAvatarJob::class, SendChatMessageJob::class]);
        $this->user->forceFill(['chat_signature' => 'Ana'])->save();

        $this->actingAs($this->user)->post(route('chat.api.send', $this->chat->id), ['file' => UploadedFile::fake()->image('foto.png'), 'client_id' => 'c1'], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->user)->post(route('chat.api.send', $this->chat->id), ['file' => UploadedFile::fake()->image('foto2.png'), 'text' => 'veja', 'client_id' => 'c2'], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->user)->post(route('chat.api.send', $this->chat->id), ['file' => UploadedFile::fake()->create('voz.ogg', 10, 'audio/ogg'), 'as_voice' => 1, 'client_id' => 'c3'], ['Accept' => 'application/json'])->assertCreated();

        $bodies = ChatMessage::orderBy('id')->pluck('body')->all();
        $this->assertSame('*Ana (CRM):*', $bodies[0]);
        $this->assertSame("*Ana (CRM):*\nveja", $bodies[1]);
        $this->assertNull($bodies[2]);
    }

    public function test_the_chat_page_knows_the_signature(): void
    {
        $this->user->forceFill(['chat_signature' => 'Ana'])->save();

        $this->actingAs($this->user)->get(route('chat.index'))->assertOk()->assertSee('data-signature="Ana"', false);
    }
}
