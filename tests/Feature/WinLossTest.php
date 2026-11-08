<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\LostReason;
use App\Models\User;
use App\Services\Reports\WinLossReport;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class WinLossTest extends TestCase
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

    private function deal(string $status, ?string $reason = null, array $o = []): Deal
    {
        return Deal::forceCreate($o + ['user_id' => $this->owner->id, 'title' => 'N', 'value' => 100, 'status' => $status, 'lost_reason' => $reason,
            'pipeline_id' => 1, 'stage_id' => 1, 'closed_at' => now()->subDay()]);
    }

    public function test_report_groups_lost_reasons_case_insensitively(): void
    {
        $this->deal('won', null, ['origin' => 'site']);
        $this->deal('lost', 'Preço');
        $this->deal('lost', ' preço ');
        $this->deal('lost', null);
        $this->deal('lost', 'Prazo', ['closed_at' => now()->subDays(200)]);   // outside the period
        $r = app(WinLossReport::class)->build($this->owner, now()->subDays(29)->startOfDay(), now()->endOfDay());

        $this->assertSame([1, 3], [$r['won'], $r['lost']]);
        $this->assertSame(25.0, $r['win_rate']);
        $this->assertSame(2, $r['reasons'][0]['count']);
        $this->assertNull($r['reasons'][1]['reason']);
        $this->assertSame('site', $r['by_origin'][0]['key'] ?? $r['by_origin'][1]['key']);
    }

    public function test_page_and_catalog_are_scoped_to_the_account(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        LostReason::create(['user_id' => $other->id, 'name' => 'Segredo']);
        $this->actingAs($this->owner)->post(route('pipeline.lost-reasons.store'), ['name' => 'Preço alto'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('pipeline.lost-reasons.store'), ['name' => 'Preço alto']);
        $this->assertSame(1, LostReason::where('user_id', $this->owner->id)->count());

        $this->actingAs($this->owner)->get(route('pipeline.winloss'))->assertOk()->assertSee('Preço alto')->assertDontSee('Segredo');
        $this->assertSame(['Preço alto'], app(WinLossReport::class)->options($this->owner));

        $id = $other->id;
        $this->actingAs($this->owner)->delete(route('pipeline.lost-reasons.destroy', LostReason::where('user_id', $id)->value('id')));
        $this->assertSame(1, LostReason::where('user_id', $id)->count());
    }
}
