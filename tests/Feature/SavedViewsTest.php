<?php

namespace Tests\Feature;

use App\Models\SavedView;
use App\Models\User;
use App\Services\SavedViews;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class SavedViewsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    private function member(string $name, string $role = TeamRoles::AGENT): User
    {
        $m = User::create(['name' => $name, 'email' => "$name@t.dev", 'username' => "$name@t.dev", 'password' => 'secret123']);
        $m->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => $role])->save();

        return $m->fresh();
    }

    public function test_only_known_filters_are_kept_and_the_view_opens_with_them(): void
    {
        $this->assertSame('tier=hot&sort=score', SavedViews::clean('contacts', 'tier=hot&sort=score&page=3&evil=<script>&search='));
        $this->assertSame('', SavedViews::clean('contacts', 'x[]=1&tier[]=a'));

        $this->actingAs($this->owner)->post(route('saved-views.store'), ['scope' => 'contacts', 'name' => 'Quentes', 'query' => 'tier=hot&page=2&foo=bar', 'shared' => 1])
            ->assertRedirect(route('contacts.index') . '?tier=hot');
        $v = SavedView::firstOrFail();
        $this->assertSame('tier=hot', $v->query);
        $this->assertSame(route('contacts.index') . '?tier=hot', SavedViews::url($v));

        $this->actingAs($this->owner)->get(route('contacts.index'))->assertOk()->assertSee('Quentes');
    }

    public function test_invalid_scope_is_refused_and_same_name_updates_instead_of_duplicating(): void
    {
        $this->actingAs($this->owner)->post(route('saved-views.store'), ['scope' => 'admin', 'name' => 'x', 'query' => ''])->assertSessionHasErrors('scope');
        foreach (['tier=hot', 'tier=cold'] as $q) {
            $this->actingAs($this->owner)->post(route('saved-views.store'), ['scope' => 'contacts', 'name' => 'Minha', 'query' => $q]);
        }
        $this->assertSame(1, SavedView::count());
        $this->assertSame('tier=cold', SavedView::first()->query);
    }

    public function test_shared_views_are_for_the_team_and_private_ones_only_for_their_creator(): void
    {
        $bia = $this->member('bia');
        $caio = $this->member('caio');
        $this->actingAs($bia)->post(route('saved-views.store'), ['scope' => 'tasks', 'name' => 'Da Bia', 'query' => 'tab=done'])->assertRedirect();
        $this->actingAs($bia)->post(route('saved-views.store'), ['scope' => 'tasks', 'name' => 'Pública', 'query' => 'scope=all', 'shared' => 1])->assertRedirect();

        $page = fn ($u) => $this->actingAs($u)->get(route('tasks.index'))->assertOk();
        $page($bia)->assertSee('Da Bia')->assertSee('Pública');
        $page($caio)->assertSee('Pública')->assertDontSee('Da Bia');
        $page($this->owner)->assertSee('Pública')->assertDontSee('Da Bia');

        // delete: another agent cannot, the creator and the owner can
        $pub = SavedView::where('name', 'Pública')->first();
        $this->actingAs($caio)->delete(route('saved-views.destroy', $pub->id))->assertStatus(404);
        $this->actingAs($bia)->delete(route('saved-views.destroy', $pub->id))->assertRedirect();
        $this->assertSame(1, SavedView::count());
    }

    public function test_views_of_another_account_are_untouchable(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $v = SavedView::create(['user_id' => $other->id, 'created_by' => $other->id, 'scope' => 'contacts', 'name' => 'x', 'query' => '', 'shared' => true]);

        $this->actingAs($this->owner)->delete(route('saved-views.destroy', $v->id))->assertStatus(404);
        $this->assertSame(1, SavedView::count());
    }
}
