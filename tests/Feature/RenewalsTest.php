<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Services\Pipeline\Renewals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class RenewalsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->contact = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5511999990000']);
    }

    private function sub(string $cycle, float $value, array $o = []): Deal
    {
        return Deal::forceCreate($o + ['user_id' => $this->owner->id, 'contact_id' => $this->contact->id, 'title' => 'Plano ' . $cycle, 'value' => $value, 'status' => 'won',
            'billing_cycle' => $cycle, 'closed_at' => now(), 'pipeline_id' => 1, 'stage_id' => 1]);
    }

    public function test_winning_a_subscription_schedules_the_first_renewal(): void
    {
        $d = $this->sub('quarterly', 300);
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), $d->fresh()->renews_at->toDateString());
        $one = Deal::forceCreate(['user_id' => $this->owner->id, 'title' => 'Avulso', 'value' => 10, 'status' => 'won', 'closed_at' => now(), 'pipeline_id' => 1, 'stage_id' => 1]);
        $this->assertNull($one->fresh()->renews_at);
    }

    public function test_mrr_normalises_cycles_and_ignores_churn(): void
    {
        $this->sub('monthly', 100);
        $this->sub('quarterly', 300);
        $this->sub('yearly', 1200);
        $this->sub('monthly', 999, ['churned_at' => now()]);
        $s = app(Renewals::class)->summary($this->owner);
        $this->assertSame(300.0, $s['mrr']);
        $this->assertSame(3600.0, $s['arr']);
        $this->assertSame([3, 1], [$s['count'], $s['churned']]);
    }

    public function test_run_creates_one_task_per_renewal_date_and_renew_rearms_it(): void
    {
        $due = $this->sub('monthly', 100, ['renews_at' => now()->addDays(10)->toDateString()]);
        $this->sub('monthly', 100, ['renews_at' => now()->addDays(40)->toDateString()]);
        $svc = app(Renewals::class);

        $this->assertSame(1, $svc->run());
        $this->assertSame(0, $svc->run());
        $this->assertSame(1, Task::where('deal_id', $due->id)->count());

        $svc->renew($due->fresh());
        $d = $due->fresh();
        $this->assertSame(now()->addDays(10)->addMonthNoOverflow()->toDateString(), $d->renews_at->toDateString());
        $this->assertNull($d->renewal_notified_at);
    }

    public function test_cancel_marks_churn_and_contact_only_when_no_other_active_subscription(): void
    {
        $a = $this->sub('monthly', 100);
        $b = $this->sub('yearly', 1200);
        $svc = app(Renewals::class);
        $svc->cancel($a);
        $this->assertNotNull($a->fresh()->churned_at);
        $this->assertNotSame('churned', $this->contact->fresh()->lifecycle_stage);
        $svc->cancel($b);
        $this->assertSame('churned', $this->contact->fresh()->lifecycle_stage);
    }

    public function test_page_actions_and_scoping(): void
    {
        $d = $this->sub('monthly', 100, ['renews_at' => now()->subDay()->toDateString()]);
        $this->actingAs($this->owner)->get(route('pipeline.renewals'))->assertOk()->assertSee('Plano monthly');
        $this->actingAs($this->owner)->post(route('pipeline.renewals.renew', $d->id))->assertRedirect();
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->post(route('pipeline.renewals.cancel', $d->id))->assertStatus(404);
        $this->assertNull($d->fresh()->churned_at);
    }
}
