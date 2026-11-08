<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Services\Contacts\Lifecycle;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class LifecycleTest extends TestCase
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

    private function contact(array $o = []): Contact
    {
        return Contact::create($o + ['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5511999990000']);
    }

    private function deal(Contact $c, string $status = 'open'): Deal
    {
        return Deal::forceCreate(['user_id' => $this->owner->id, 'contact_id' => $c->id, 'title' => 'N', 'value' => 1, 'status' => $status, 'pipeline_id' => 1, 'stage_id' => 1]);
    }

    public function test_deals_move_the_person_forward_only(): void
    {
        $c = $this->contact();
        $deal = $this->deal($c);
        $this->assertSame('opportunity', $c->fresh()->lifecycle_stage);

        $deal->update(['status' => 'won']);
        $this->assertSame('customer', $c->fresh()->lifecycle_stage);

        $this->deal($c);                      // a new open deal does not demote a customer
        $this->assertSame('customer', $c->fresh()->lifecycle_stage);
    }

    public function test_manual_set_can_go_back_and_churned_returns_with_a_won_deal(): void
    {
        $svc = app(Lifecycle::class);
        $c = $this->contact(['lifecycle_stage' => 'customer']);
        $this->assertTrue($svc->set($c, 'churned'));
        $this->assertFalse($svc->advance($c->fresh(), 'opportunity'));
        $this->deal($c, 'won');
        $this->assertSame('customer', $c->fresh()->lifecycle_stage);
        $this->assertFalse($svc->set($c->fresh(), 'bogus'));
    }

    public function test_bulk_and_filter_only_touch_own_contacts(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $theirs = Contact::create(['user_id' => $other->id, 'name' => 'Bia', 'contact' => '5511888880000']);
        $a = $this->contact();
        $b = $this->contact(['contact' => '5511777770000', 'name' => 'Caio']);

        $this->actingAs($this->owner)->post(route('contacts.bulk-update'), ['ids' => json_encode([$a->id, $theirs->id]), 'action' => 'lifecycle', 'stage' => 'qualified'])->assertRedirect();
        $this->assertSame('qualified', $a->fresh()->lifecycle_stage);
        $this->assertNull($theirs->fresh()->lifecycle_stage);

        $html = $this->actingAs($this->owner)->get(route('contacts.index', ['stage' => 'qualified']))->assertOk()->getContent();
        $this->assertStringContainsString('Ana', $html);
        $this->assertStringNotContainsString('Caio', $html);
        $this->assertSame(['lead' => 1, 'qualified' => 1, 'opportunity' => 0, 'customer' => 0, 'churned' => 0], app(Lifecycle::class)->counts($this->owner));
    }
}
