<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\Team\TeamRoles;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class QuickRepliesTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $bia;
    private Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Loja Sol', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->bia = User::create(['name' => 'Bia Souza', 'email' => 'b@t.dev', 'username' => 'b@t.dev', 'password' => 'secret123']);
        $this->bia->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT, 'chat_signature' => 'Bia'])->save();
        $this->bia = $this->bia->fresh();

        $i = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => '5595111', 'status' => 'connected']);
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana Maria Lima', 'contact' => '5595900000001']);
        $this->chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $i->id, 'remote_jid' => '5595900000001@s.whatsapp.net', 'phone' => '5595900000001', 'contact_id' => $c->id, 'last_message_at' => now()]);
    }

    private function reply(string $shortcut, string $body, string $title = 'T'): QuickReply
    {
        return QuickReply::create(['user_id' => $this->owner->id, 'shortcut' => $shortcut, 'title' => $title, 'body' => $body]);
    }

    public function test_team_member_creates_edits_and_removes_shared_replies(): void
    {
        $this->actingAs($this->bia)->post('/replies', ['shortcut' => ' /Preço Tabela ', 'title' => 'Preços', 'body' => 'Olá'])->assertRedirect('/replies');
        $r = QuickReply::sole();
        $this->assertSame('preçotabela', $r->shortcut); // lower case, no slash, no spaces
        $this->assertSame($this->owner->id, (int) $r->user_id); // on the account, shared
        $this->assertSame($this->bia->id, (int) $r->created_by);

        $this->actingAs($this->owner)->put("/replies/{$r->id}", ['shortcut' => 'precos', 'title' => 'Preços', 'body' => 'Novo'])->assertRedirect('/replies');
        $this->assertSame('Novo', $r->fresh()->body);
        $this->actingAs($this->owner)->get('/replies')->assertOk()->assertSee('/precos');

        $this->actingAs($this->bia)->delete("/replies/{$r->id}")->assertRedirect('/replies');
        $this->assertSame(0, QuickReply::count());
    }

    public function test_shortcuts_are_unique_per_account_and_validated(): void
    {
        $this->reply('oi', 'a');
        $this->actingAs($this->owner)->post('/replies', ['shortcut' => '/OI', 'title' => 't', 'body' => 'b'])->assertSessionHasErrors('shortcut');
        $this->actingAs($this->owner)->post('/replies', ['shortcut' => '///', 'title' => 't', 'body' => 'b'])->assertSessionHasErrors('shortcut');
        $this->actingAs($this->owner)->post('/replies', ['shortcut' => 'x', 'title' => '', 'body' => 'b'])->assertSessionHasErrors('title');

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->post('/replies', ['shortcut' => 'oi', 'title' => 't', 'body' => 'b'])->assertRedirect(); // another account may reuse it
        $this->assertSame(2, QuickReply::count());
    }

    public function test_search_fills_the_variables_for_the_open_conversation(): void
    {
        $this->reply('ola', 'Olá {{first_name}}! Aqui é {{agent}} da {{company}}. Seu número: {{phone}}. {{unknown}} {{ name }}', 'Saudação');

        $r = $this->actingAs($this->bia)->getJson('/replies/search?q=ol&chat=' . $this->chat->id)->assertOk()->json('items.0');
        $this->assertSame('ola', $r['shortcut']);
        $this->assertSame('Olá Ana! Aqui é Bia da Loja Sol. Seu número: +5595900000001. {{unknown}} Ana Maria Lima', $r['text']);
    }

    public function test_a_phone_number_is_not_used_as_a_name(): void
    {
        $this->reply('ola', 'Oi {{first_name}}|{{name}}');
        $this->chat->contact()->dissociate();
        $this->chat->forceFill(['contact_id' => null, 'name' => '+5595900000001'])->save();
        $this->assertSame('Oi |', $this->actingAs($this->owner)->getJson('/replies/search?chat=' . $this->chat->id)->json('items.0.text'));
    }

    public function test_search_matches_shortcut_or_title_and_orders_by_prefix_then_use(): void
    {
        $this->reply('preco', 'a', 'Tabela');
        $this->reply('apreco', 'b', 'Outra');
        $this->reply('pagamento', 'c', 'Formas de preco');
        $s = fn ($q) => collect($this->actingAs($this->owner)->getJson('/replies/search?q=' . $q)->json('items'))->pluck('shortcut')->all();
        $this->assertSame(['preco', 'apreco', 'pagamento'], $s('preco'));
        $this->assertSame([], $s('zzz'));
        $this->assertCount(3, $s(''));
    }

    public function test_search_never_returns_another_accounts_replies_or_chats(): void
    {
        $this->reply('meu', 'segredo {{name}}');
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->assertSame([], $this->actingAs($other)->getJson('/replies/search?chat=' . $this->chat->id)->json('items'));
        QuickReply::create(['user_id' => $other->id, 'shortcut' => 'x', 'title' => 't', 'body' => 'Oi {{name}}']);
        $this->assertSame('Oi ', $this->actingAs($other)->getJson('/replies/search?chat=' . $this->chat->id)->json('items.0.text')); // the foreign chat is not used
    }

    public function test_used_counts_and_roles_without_chat_are_refused(): void
    {
        $r = $this->reply('oi', 'a');
        $this->actingAs($this->bia)->postJson("/replies/{$r->id}/used")->assertOk();
        $this->assertSame(1, (int) $r->fresh()->uses);

        $analyst = User::create(['name' => 'an', 'email' => 'an@t.dev', 'username' => 'an@t.dev', 'password' => 'secret123']);
        $analyst->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::ANALYST])->save();
        $this->actingAs($analyst->fresh())->get('/replies')->assertForbidden();
    }
}
