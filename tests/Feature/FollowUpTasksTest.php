<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDue;
use App\Services\Tasks\FollowUps;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class FollowUpTasksTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $bia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->bia = $this->member('bia', TeamRoles::AGENT);
    }

    private function member(string $name, string $role): User
    {
        $m = User::create(['name' => $name, 'email' => "$name@t.dev", 'username' => "$name@t.dev", 'password' => 'secret123']);
        $m->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => $role])->save();

        return $m->fresh();
    }

    private function chat(): Chat
    {
        $i = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => '5595111', 'status' => 'connected']);
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Ana', 'contact' => '5595900000001']);

        return Chat::create(['user_id' => $this->owner->id, 'instance_id' => $i->id, 'remote_jid' => '5595900000001@s.whatsapp.net', 'phone' => '5595900000001', 'contact_id' => $c->id, 'last_message_at' => now()]);
    }

    public function test_task_is_created_from_the_page_for_me_by_default_and_listed_by_tab(): void
    {
        $this->actingAs($this->bia)->post('/tasks', ['title' => 'Ligar para a Ana', 'due_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertRedirect('/tasks');
        $t = Task::sole();
        $this->assertSame($this->bia->id, (int) $t->assigned_to);
        $this->assertSame($this->owner->id, (int) $t->user_id); // on the owner's account

        Task::create(['user_id' => $this->owner->id, 'title' => 'Atrasada', 'due_at' => now()->subHour(), 'assigned_to' => $this->bia->id]);
        $this->actingAs($this->bia)->get('/tasks?tab=overdue')->assertOk()->assertSee('Atrasada')->assertDontSee('Ligar para a Ana');
        $this->actingAs($this->bia)->get('/tasks')->assertOk()->assertSee('Ligar para a Ana')->assertSee('Atrasada');
    }

    public function test_mine_scope_hides_the_tasks_of_other_people(): void
    {
        Task::create(['user_id' => $this->owner->id, 'title' => 'Do dono', 'assigned_to' => $this->owner->id]);
        Task::create(['user_id' => $this->owner->id, 'title' => 'Da Bia', 'assigned_to' => $this->bia->id]);

        $this->actingAs($this->bia)->get('/tasks')->assertSee('Da Bia')->assertDontSee('Do dono'); // members start in "mine"
        $this->actingAs($this->bia)->get('/tasks?scope=all')->assertSee('Do dono');
    }

    public function test_only_people_of_the_account_and_its_own_contacts_can_be_linked(): void
    {
        $stranger = User::create(['name' => 'X', 'email' => 'x@t.dev', 'username' => 'x', 'password' => 'x']);
        $theirs = Contact::create(['user_id' => $stranger->id, 'name' => 'Alheio', 'contact' => '5511900000000']);

        $this->actingAs($this->owner)->postJson('/tasks', ['title' => 'x', 'assigned_to' => $stranger->id])->assertStatus(422);
        $this->actingAs($this->owner)->postJson('/tasks', ['title' => 'x', 'contact_id' => $theirs->id])->assertStatus(422);
        $this->actingAs($this->owner)->postJson('/tasks', ['title' => ''])->assertStatus(422);
        $this->assertSame(0, Task::count());
    }

    public function test_task_from_the_chat_takes_the_contact_and_shows_in_the_panel(): void
    {
        $chat = $this->chat();
        $this->actingAs($this->bia)->postJson('/tasks', ['title' => 'Enviar proposta', 'due_at' => now()->subMinute()->format('Y-m-d\TH:i'), 'chat_id' => $chat->id])->assertOk();
        $t = Task::sole();
        $this->assertSame($chat->contact_id, (int) $t->contact_id);

        $crm = $this->actingAs($this->bia)->getJson("/chat/api/chats/{$chat->id}/crm")->assertOk()->json();
        $this->assertTrue($crm['can_tasks']);
        $this->assertSame('Enviar proposta', $crm['tasks'][0]['title']);
        $this->assertTrue($crm['tasks'][0]['overdue']);

        $this->actingAs($this->bia)->postJson("/tasks/{$t->id}/done")->assertOk()->assertJsonPath('done', true);
        $this->assertSame([], $this->actingAs($this->bia)->getJson("/chat/api/chats/{$chat->id}/crm")->json('tasks'));
        $this->actingAs($this->bia)->postJson("/tasks/{$t->id}/done")->assertJsonPath('done', false); // reopened
    }

    public function test_roles_without_the_permission_are_refused(): void
    {
        $analyst = $this->member('ana', TeamRoles::ANALYST);
        $this->actingAs($analyst)->get('/tasks')->assertForbidden();
        $this->assertTrue(TeamRoles::allows(TeamRoles::AGENT, 'tasks'));
        $this->assertFalse(TeamRoles::allows(TeamRoles::SENDER, 'tasks'));
    }

    public function test_another_account_cannot_touch_a_task(): void
    {
        $t = Task::create(['user_id' => $this->owner->id, 'title' => 'Minha']);
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->postJson("/tasks/{$t->id}/done")->assertNotFound();
        $this->actingAs($other)->deleteJson("/tasks/{$t->id}")->assertNotFound();
        $this->assertSame(1, Task::count());
    }

    public function test_due_tasks_notify_the_person_responsible_once(): void
    {
        Notification::fake();
        $due = Task::create(['user_id' => $this->owner->id, 'title' => 'Vence', 'due_at' => now()->subMinute(), 'assigned_to' => $this->bia->id]);
        Task::create(['user_id' => $this->owner->id, 'title' => 'Futura', 'due_at' => now()->addHour(), 'assigned_to' => $this->bia->id]);
        Task::create(['user_id' => $this->owner->id, 'title' => 'Feita', 'due_at' => now()->subMinute(), 'assigned_to' => $this->bia->id, 'done_at' => now()]);
        Task::create(['user_id' => $this->owner->id, 'title' => 'Sem hora']);

        $this->assertSame(1, app(FollowUps::class)->remindDue());
        Notification::assertSentTo($this->bia, TaskDue::class, fn ($n) => $n->task->id === $due->id);
        $this->assertSame(0, app(FollowUps::class)->remindDue()); // once

        app(FollowUps::class)->reschedule($due->fresh(), now()->subSecond()->format('Y-m-d\TH:i:s')); // new time, new reminder
        $this->assertSame(1, app(FollowUps::class)->remindDue());
    }

    public function test_reminder_without_assignee_goes_to_the_owner_and_command_runs(): void
    {
        Notification::fake();
        Task::create(['user_id' => $this->owner->id, 'title' => 'Sem dono', 'due_at' => now()->subMinute()]);
        $this->artisan('tasks:remind')->assertSuccessful();
        Notification::assertSentTo($this->owner, TaskDue::class);
    }
}
