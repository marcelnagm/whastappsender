<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\User;
use App\Services\Team\TeamRoles;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    public function test_deletion_is_recorded_without_content(): void
    {
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Segredo', 'contact' => '5595999990000']);
        $this->actingAs($this->owner)->delete(route('contacts.destroy', $c->id))->assertRedirect();

        $log = AuditLog::first();
        $this->assertSame('contacts.destroy', $log->action);
        $this->assertSame((string) $c->id, $log->subject);
        $this->assertSame('Dono', $log->actor_name);
        $this->assertStringNotContainsString('Segredo', json_encode($log->toArray()));
    }

    public function test_plain_reads_and_failed_calls_are_not_recorded(): void
    {
        $this->actingAs($this->owner)->get(route('contacts.index'))->assertOk();
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $c = Contact::create(['user_id' => $other->id, 'name' => 'X', 'contact' => '5595888880000']);
        $this->actingAs($this->owner)->delete(route('contacts.destroy', $c->id))->assertNotFound();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_member_actions_name_the_real_person(): void
    {
        $m = User::create(['name' => 'Maria', 'email' => 'm@t.dev', 'username' => 'm', 'password' => 'x'])->fresh();
        $m->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::ADMIN, 'active' => true])->save();
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5595999990000']);
        $this->actingAs($m)->delete(route('contacts.destroy', $c->id));
        $log = AuditLog::first();
        $this->assertSame('Maria', $log->actor_name);
        $this->assertSame($this->owner->id, (int) $log->user_id);
    }

    public function test_page_lists_filters_and_is_closed_to_agents(): void
    {
        AuditLog::create(['user_id' => $this->owner->id, 'actor_id' => $this->owner->id, 'actor_name' => 'Dono', 'action' => 'team.destroy', 'method' => 'DELETE', 'path' => '/team/1', 'subject' => '1', 'ip' => '1.1.1.1', 'created_at' => now()]);
        $this->actingAs($this->owner)->get(route('audit.index'))->assertOk()->assertSee('team.destroy');
        $this->actingAs($this->owner)->get(route('audit.index', ['q' => 'contacts']))->assertOk()->assertDontSee('team.destroy');

        $agent = User::create(['name' => 'Ag', 'email' => 'a@t.dev', 'username' => 'a', 'password' => 'x'])->fresh();
        $agent->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT, 'active' => true])->save();
        $this->actingAs($agent)->get(route('audit.index'))->assertForbidden();
    }

    public function test_prune_keeps_recent_entries(): void
    {
        foreach ([400, 10] as $days) {
            AuditLog::create(['user_id' => 1, 'action' => 'x', 'method' => 'POST', 'path' => '/x', 'created_at' => now()->subDays($days)]);
        }
        $this->artisan('audit:prune')->assertExitCode(0);
        $this->assertSame(1, AuditLog::count());
    }
}
