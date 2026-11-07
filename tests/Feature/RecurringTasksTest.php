<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\FollowUps;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class RecurringTasksTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    private function task(string $rec, $due): Task
    {
        return Task::create(['user_id' => $this->owner->id, 'title' => 'Relatório', 'due_at' => $due, 'assigned_to' => $this->owner->id, 'created_by' => $this->owner->id, 'recurrence' => $rec]);
    }

    public function test_completing_creates_the_next_occurrence_once(): void
    {
        $t = $this->task('weekly', now()->addDay());
        $svc = app(FollowUps::class);
        $svc->toggle($t, $this->owner);

        $next = Task::where('id', '!=', $t->id)->first();
        $this->assertSame('weekly', $next->recurrence);
        $this->assertTrue($next->due_at->isSameDay($t->due_at->copy()->addWeek()));
        $this->assertNull($next->done_at);

        $svc->toggle($t->fresh(), $this->owner); // reopen
        $svc->toggle($t->fresh(), $this->owner); // complete again
        $this->assertSame(2, Task::count());
    }

    public function test_late_completion_skips_past_dates(): void
    {
        $t = $this->task('daily', now()->subDays(5));
        app(FollowUps::class)->toggle($t, $this->owner);
        $this->assertTrue(Task::where('id', '!=', $t->id)->first()->due_at->isFuture());
    }

    public function test_monthly_does_not_overflow_and_plain_tasks_do_not_repeat(): void
    {
        $t = $this->task('monthly', now()->addDay()->setDate(now()->year + 1, 1, 31)->setTime(9, 0));
        app(FollowUps::class)->toggle($t, $this->owner);
        $this->assertSame('02-28', Task::where('id', '!=', $t->id)->first()->due_at->format('m-d'));

        $plain = Task::create(['user_id' => $this->owner->id, 'title' => 'x', 'due_at' => now()->addDay(), 'assigned_to' => $this->owner->id, 'created_by' => $this->owner->id]);
        app(FollowUps::class)->toggle($plain, $this->owner);
        $this->assertSame(3, Task::count());
    }

    public function test_store_accepts_recurrence_only_with_a_due_date(): void
    {
        $this->actingAs($this->owner)->post(route('tasks.store'), ['title' => 'A', 'due_at' => now()->addDay()->format('Y-m-d\TH:i'), 'recurrence' => 'daily'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('tasks.store'), ['title' => 'B', 'recurrence' => 'daily'])->assertRedirect();
        $this->assertSame('daily', Task::where('title', 'A')->first()->recurrence);
        $this->assertNull(Task::where('title', 'B')->first()->recurrence);
        $this->actingAs($this->owner)->post(route('tasks.store'), ['title' => 'C', 'recurrence' => 'hourly'])->assertSessionHasErrors('recurrence');
    }
}
