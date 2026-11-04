<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Pipeline\SalesPipeline;
use App\Services\Team\TeamRoles;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class SalesPipelineTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->user = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    private function stage(Pipeline $p, string $kind, int $nth = 0): PipelineStage
    {
        return PipelineStage::where('pipeline_id', $p->id)->where('kind', $kind)->orderBy('position')->skip($nth)->firstOrFail();
    }

    public function test_board_creates_the_default_pipeline_with_stages(): void
    {
        $this->actingAs($this->user)->get('/pipeline')->assertOk()->assertSee('Sales pipeline');

        $p = Pipeline::where('user_id', $this->user->id)->first();
        $this->assertTrue($p->is_default);
        $this->assertSame(6, PipelineStage::where('pipeline_id', $p->id)->count());
        $this->assertSame(1, Pipeline::count()); // opening again does not duplicate
        $this->actingAs($this->user)->get('/pipeline')->assertOk();
        $this->assertSame(1, Pipeline::count());
    }

    public function test_a_deal_is_created_in_the_first_open_stage_with_contact_and_value(): void
    {
        $c = Contact::create(['user_id' => $this->user->id, 'name' => 'Ana', 'contact' => '5511999990000']);
        $this->actingAs($this->user)->get('/pipeline');
        $p = Pipeline::first();

        $this->actingAs($this->user)->post("/pipeline/pipelines/{$p->id}/deals", ['title' => 'Site novo', 'value' => '1500.50', 'contact_id' => $c->id])->assertRedirect();

        $d = Deal::first();
        $this->assertSame('Site novo', $d->title);
        $this->assertSame('open', $d->status);
        $this->assertSame($this->stage($p, 'open')->id, $d->stage_id);
        $this->assertSame($c->id, $d->contact_id);
        $this->assertSame('1500.50', (string) $d->value);
        $this->actingAs($this->user)->get('/pipeline')->assertSee('Site novo')->assertSee('Ana');
    }

    public function test_moving_to_won_and_lost_closes_the_deal_and_other_stages_reopen_it(): void
    {
        $p = app(SalesPipeline::class)->defaultFor($this->user);
        $d = app(SalesPipeline::class)->open($p, ['title' => 'A', 'value' => 100]);

        $this->actingAs($this->user)->postJson("/pipeline/deals/{$d->id}/move", ['stage_id' => $this->stage($p, 'won')->id])->assertOk()->assertJsonPath('status', 'won')->assertJsonPath('summary.won', 1);
        $this->assertNotNull($d->fresh()->closed_at);

        $this->actingAs($this->user)->postJson("/pipeline/deals/{$d->id}/move", ['stage_id' => $this->stage($p, 'lost')->id, 'lost_reason' => 'Preço'])->assertJsonPath('status', 'lost');
        $this->assertSame('Preço', $d->fresh()->lost_reason);

        $this->actingAs($this->user)->postJson("/pipeline/deals/{$d->id}/move", ['stage_id' => $this->stage($p, 'open', 2)->id])->assertJsonPath('status', 'open');
        $d = $d->fresh();
        $this->assertNull($d->closed_at);
        $this->assertNull($d->lost_reason);
    }

    public function test_position_inside_a_stage_is_kept(): void
    {
        $svc = app(SalesPipeline::class);
        $p = $svc->defaultFor($this->user);
        $a = $svc->open($p, ['title' => 'A']);
        $b = $svc->open($p, ['title' => 'B']);
        $c = $svc->open($p, ['title' => 'C']);

        $svc->move($c, $this->stage($p, 'open'), 0);

        $order = Deal::orderBy('position')->pluck('title')->all();
        $this->assertSame(['C', 'A', 'B'], $order);
    }

    public function test_summary_counts_values_and_win_rate(): void
    {
        $svc = app(SalesPipeline::class);
        $p = $svc->defaultFor($this->user);
        $svc->open($p, ['title' => 'open', 'value' => 10]);
        $w = $svc->open($p, ['title' => 'won', 'value' => 200]);
        $l = $svc->open($p, ['title' => 'lost', 'value' => 50]);
        $svc->move($w, $this->stage($p, 'won'));
        $svc->move($l, $this->stage($p, 'lost'));

        $s = $svc->summary($p);
        $this->assertSame(1, $s['open']);
        $this->assertSame(10.0, $s['open_value']);
        $this->assertSame(200.0, $s['won_value']);
        $this->assertSame(50.0, $s['win_rate']);
    }

    public function test_one_account_cannot_touch_the_deals_of_another(): void
    {
        $other = User::create(['name' => 'Outro', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $svc = app(SalesPipeline::class);
        $mine = $svc->open($svc->defaultFor($this->user), ['title' => 'meu']);
        $theirPipe = $svc->defaultFor($other);

        $this->actingAs($other)->postJson("/pipeline/deals/{$mine->id}/move", ['stage_id' => $this->stage($theirPipe, 'won')->id])->assertNotFound();
        $this->actingAs($other)->delete("/pipeline/deals/{$mine->id}")->assertNotFound();
        $this->actingAs($other)->post("/pipeline/pipelines/" . $svc->defaultFor($this->user)->id . "/deals", ['title' => 'x'])->assertNotFound();

        // a contact of someone else is not linked
        $c = Contact::create(['user_id' => $this->user->id, 'name' => 'Ana', 'contact' => '551100']);
        $this->actingAs($other)->post("/pipeline/pipelines/{$theirPipe->id}/deals", ['title' => 'y', 'contact_id' => $c->id]);
        $this->assertNull(Deal::where('title', 'y')->value('contact_id'));
    }

    public function test_a_deal_cannot_be_moved_to_a_stage_of_another_pipeline(): void
    {
        $svc = app(SalesPipeline::class);
        $p = $svc->defaultFor($this->user);
        $second = $svc->create($this->user, 'Parcerias');
        $d = $svc->open($p, ['title' => 'A']);

        $this->actingAs($this->user)->postJson("/pipeline/deals/{$d->id}/move", ['stage_id' => $this->stage($second, 'won')->id])->assertNotFound();
    }

    public function test_stages_can_be_added_but_not_removed_while_they_hold_deals(): void
    {
        $svc = app(SalesPipeline::class);
        $p = $svc->defaultFor($this->user);
        $this->actingAs($this->user)->post("/pipeline/pipelines/{$p->id}/stages", ['name' => 'Reunião', 'kind' => 'open'])->assertRedirect();
        $s = PipelineStage::where('name', 'Reunião')->first();
        $this->assertSame(6, $s->position);

        $svc->open($p, ['title' => 'A'], $s);
        $this->actingAs($this->user)->delete("/pipeline/stages/{$s->id}")->assertSessionHas('error');
        $this->assertNotNull($s->fresh());

        Deal::query()->delete();
        $this->actingAs($this->user)->delete("/pipeline/stages/{$s->id}")->assertSessionHas('success');
        $this->assertNull($s->fresh());
    }

    public function test_the_last_pipeline_cannot_be_removed_but_another_can(): void
    {
        $svc = app(SalesPipeline::class);
        $p = $svc->defaultFor($this->user);
        $this->actingAs($this->user)->delete("/pipeline/pipelines/{$p->id}")->assertSessionHas('error');

        $second = $svc->create($this->user, 'Parcerias');
        $svc->open($second, ['title' => 'x']);
        $this->actingAs($this->user)->delete("/pipeline/pipelines/{$second->id}")->assertSessionHas('success');
        $this->assertSame(0, Deal::count());
        $this->assertSame(0, PipelineStage::where('pipeline_id', $second->id)->count());
    }

    public function test_team_roles_attendant_works_the_pipeline_but_analyst_does_not(): void
    {
        $this->assertTrue(TeamRoles::allows(TeamRoles::AGENT, 'deals'));
        $this->assertTrue(TeamRoles::allows(TeamRoles::ADMIN, 'deals'));
        $this->assertFalse(TeamRoles::allows(TeamRoles::ANALYST, 'deals'));

        $agent = User::create(['name' => 'Atend', 'email' => 'a@t.dev', 'username' => 'a@t.dev', 'password' => 'secret123']);
        $agent->forceFill(['parent_user_id' => $this->user->id, 'team_role' => TeamRoles::AGENT])->save();
        $agent = $agent->fresh();
        $this->actingAs($agent)->get('/pipeline')->assertOk();
        $this->assertSame(1, Pipeline::where('user_id', $this->user->id)->count()); // works on the owner's account

        $analyst = User::create(['name' => 'An', 'email' => 'an@t.dev', 'username' => 'an@t.dev', 'password' => 'secret123']);
        $analyst->forceFill(['parent_user_id' => $this->user->id, 'team_role' => TeamRoles::ANALYST])->save();
        $analyst = $analyst->fresh();
        $this->actingAs($analyst)->get('/pipeline')->assertForbidden();
    }
}
