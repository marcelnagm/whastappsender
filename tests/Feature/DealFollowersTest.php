<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Pipeline\DealFollowers;
use App\Services\Pipeline\SalesPipeline;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class DealFollowersTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $fan;
    private Pipeline $p;
    private array $st = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->fan = User::create(['name' => 'Fã', 'email' => 'f@t.dev', 'username' => 'f', 'password' => 'x', 'parent_user_id' => $this->owner->id, 'team_role' => 'agent'])->fresh();
        $this->p = Pipeline::forceCreate(['user_id' => $this->owner->id, 'name' => 'Vendas']);
        foreach ([['Novo', 'open'], ['Proposta', 'open'], ['Ganho', 'won']] as $i => [$n, $k]) {
            $this->st[$n] = PipelineStage::forceCreate(['user_id' => $this->owner->id, 'pipeline_id' => $this->p->id, 'name' => $n, 'kind' => $k, 'position' => $i + 1]);
        }
    }

    public function test_followers_are_told_about_stage_changes_except_the_one_who_moved_it(): void
    {
        $deal = app(SalesPipeline::class)->open($this->p, ['title' => 'Plano', 'value' => 10]);
        $svc = app(DealFollowers::class);
        $svc->follow($deal, $this->fan->id);
        $svc->follow($deal, $this->owner->id);
        $svc->follow($deal, $this->fan->id);   // idempotent
        $this->assertCount(2, $svc->list($deal));

        $this->actingAs($this->owner);   // the owner moves it: only the fan is told
        app(SalesPipeline::class)->move($deal, $this->st['Proposta']);
        $this->assertSame(1, $this->fan->notifications()->count());
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertStringContainsString('Proposta', $this->fan->notifications()->first()->data['message']);

        app(SalesPipeline::class)->move($deal->fresh(), $this->st['Ganho']);
        $this->assertSame(3, $this->fan->notifications()->count());   // stage change + won (stage event and won event)
    }

    public function test_toggle_endpoint_follows_and_unfollows_and_is_scoped(): void
    {
        $deal = app(SalesPipeline::class)->open($this->p, ['title' => 'Plano', 'value' => 10]);
        $this->actingAs($this->owner)->putJson(route('pipeline.deals.followers.toggle', $deal->id), ['follow' => true])->assertOk()->assertJson(['following' => true]);
        $this->actingAs($this->owner)->getJson(route('pipeline.deals.followers.index', $deal->id))->assertJsonCount(1, 'followers');
        $this->actingAs($this->owner)->putJson(route('pipeline.deals.followers.toggle', $deal->id), ['follow' => false])->assertJson(['following' => false]);

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->putJson(route('pipeline.deals.followers.toggle', $deal->id), ['follow' => true])->assertStatus(404);
    }
}
