<?php

namespace Tests\Feature;

use App\Models\KbArticle;
use App\Models\User;
use App\Services\Team\TeamRoles;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    private function art(string $title, string $body, array $o = []): KbArticle
    {
        return KbArticle::create($o + ['user_id' => $this->owner->id, 'title' => $title, 'body' => $body, 'active' => true]);
    }

    public function test_search_needs_all_words_and_skips_inactive_and_other_accounts(): void
    {
        $this->art('Prazo de entrega', 'Entregamos em até 5 dias úteis.', ['keywords' => 'frete envio']);
        $this->art('Trocas', 'Aceitamos trocas em 7 dias.');
        $this->art('Rascunho entrega', 'x', ['active' => false]);
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        KbArticle::create(['user_id' => $other->id, 'title' => 'Entrega alheia', 'body' => 'y', 'active' => true]);

        $r = $this->actingAs($this->owner)->getJson(route('kb.search', ['q' => 'entrega']))->assertOk();
        $this->assertSame(['Prazo de entrega'], collect($r->json('items'))->pluck('title')->all());
        $this->assertSame(1, count($this->actingAs($this->owner)->getJson(route('kb.search', ['q' => 'frete dias']))->json('items')));
        $this->assertSame(0, count($this->actingAs($this->owner)->getJson(route('kb.search', ['q' => 'frete trocas']))->json('items')));
        $this->assertSame(2, count($this->actingAs($this->owner)->getJson(route('kb.search'))->json('items')));
    }

    public function test_crud_and_usage_counter(): void
    {
        $this->actingAs($this->owner)->post(route('kb.store'), ['title' => 'Horário', 'body' => 'Das 9h às 18h', 'active' => 1])->assertRedirect();
        $a = KbArticle::first();
        $this->actingAs($this->owner)->post(route('kb.used', $a->id))->assertOk();
        $this->assertSame(1, $a->fresh()->uses);
        $this->actingAs($this->owner)->put(route('kb.update', $a->id), ['title' => 'Horário', 'body' => 'Seg a sex', 'keywords' => 'abre'])->assertRedirect();
        $this->assertSame('Seg a sex', $a->fresh()->body);
        $this->assertFalse($a->fresh()->active); // unchecked switch = paused
        $this->actingAs($this->owner)->get(route('kb.index', ['q' => 'abre']))->assertOk()->assertSee('Seg a sex');
        $this->actingAs($this->owner)->delete(route('kb.destroy', $a->id));
        $this->assertSame(0, KbArticle::count());
    }

    public function test_other_account_cannot_edit_and_agent_can_only_search(): void
    {
        $a = $this->art('A', 'b');
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->put(route('kb.update', $a->id), ['title' => 'x', 'body' => 'y'])->assertNotFound();

        $agent = User::create(['name' => 'Ag', 'email' => 'a@t.dev', 'username' => 'a', 'password' => 'x'])->fresh();
        $agent->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT, 'active' => true])->save();
        $this->actingAs($agent)->getJson(route('kb.search', ['q' => 'a']))->assertOk();
        $this->actingAs($agent)->get(route('kb.index'))->assertForbidden();
        $this->actingAs($agent)->delete(route('kb.destroy', $a->id))->assertForbidden();
    }
}
