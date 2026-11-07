<?php

namespace App\Services\Tasks;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDue;
use App\Services\Chat\ChatAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/** Follow-up tasks of an account: creation (with the links checked), completion and the reminder that goes out when they fall due. */
class FollowUps
{
    public function __construct(private ChatAssignment $team)
    {
    }

    /**
     * @param array{title:string,notes?:?string,due_at?:?string,assigned_to?:?int,contact_id?:?int,chat_id?:?int,deal_id?:?int} $data
     */
    public const RECURRENCES = ['daily', 'weekly', 'monthly'];

    public function create(User $owner, User $actor, array $data): Task
    {
        $assignee = $data['assigned_to'] ?? $actor->id;
        abort_unless($this->team->people($owner)->contains('id', (int) $assignee), 422, __('This person is not part of the team.'));

        $chat = !empty($data['chat_id']) ? Chat::where('user_id', $owner->id)->findOrFail($data['chat_id']) : null;
        $contactId = $data['contact_id'] ?? $chat?->contact_id;
        if ($contactId) {
            abort_unless(Contact::where('user_id', $owner->id)->whereKey($contactId)->exists(), 422);
        }
        if (!empty($data['deal_id'])) {
            abort_unless(Deal::where('user_id', $owner->id)->whereKey($data['deal_id'])->exists(), 422);
        }

        return Task::create([
            'user_id' => $owner->id, 'title' => mb_substr(trim($data['title']), 0, 160), 'notes' => $data['notes'] ?? null,
            'due_at' => $this->parseDue($data['due_at'] ?? null), 'assigned_to' => (int) $assignee, 'created_by' => $actor->id,
            'contact_id' => $contactId ?: null, 'chat_id' => $chat?->id, 'deal_id' => $data['deal_id'] ?? null,
            'recurrence' => in_array($data['recurrence'] ?? null, self::RECURRENCES, true) && !empty($data['due_at']) ? $data['recurrence'] : null,
        ]);
    }

    /** "2026-11-05T09:00" (what the date field sends) in the application's time zone. */
    public function parseDue(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value, config('app.timezone')) : null;
    }

    public function toggle(Task $task, User $actor): Task
    {
        $task->forceFill($task->isDone() ? ['done_at' => null, 'done_by' => null] : ['done_at' => now(), 'done_by' => $actor->id])->save();
        if ($task->isDone()) {
            $this->spawnNext($task);
        }

        return $task;
    }

    /** A recurring task that is completed creates its next occurrence (once, however often it is toggled). */
    private function spawnNext(Task $task): void
    {
        if (!$task->recurrence || $task->next_task_id || !$task->due_at) {
            return;
        }
        $next = $task->due_at->copy();
        // jump over occurrences already in the past so that a late completion does not create an overdue task
        do {
            $next = match ($task->recurrence) {
                'daily' => $next->addDay(),
                'weekly' => $next->addWeek(),
                default => $next->addMonthNoOverflow(),
            };
        } while ($next->lte(now()));

        $copy = Task::create([
            'user_id' => $task->user_id, 'title' => $task->title, 'notes' => $task->notes, 'due_at' => $next, 'assigned_to' => $task->assigned_to,
            'created_by' => $task->created_by, 'contact_id' => $task->contact_id, 'chat_id' => $task->chat_id, 'deal_id' => $task->deal_id, 'recurrence' => $task->recurrence,
        ]);
        $task->forceFill(['next_task_id' => $copy->id])->save();
    }

    /** A new due time asks for a new reminder. */
    public function reschedule(Task $task, ?string $due): void
    {
        $task->forceFill(['due_at' => $this->parseDue($due), 'reminded_at' => null])->save();
    }

    /** Sends the reminder of every open task that has fallen due, once. @return int reminders sent */
    public function remindDue(int $limit = 500): int
    {
        $sent = 0;
        Task::with('contact')->whereNull('done_at')->whereNull('reminded_at')->whereNotNull('due_at')->where('due_at', '<=', now())
            ->orderBy('due_at')->limit($limit)->get()->each(function (Task $t) use (&$sent) {
                try {
                    $who = User::find($t->assigned_to ?: $t->user_id);
                    $who?->notify(new TaskDue($t));
                    $sent += $who ? 1 : 0;
                } catch (\Throwable $e) {
                    Log::warning('Task reminder failed: ' . $e->getMessage(), ['task_id' => $t->id]);
                }
                $t->forceFill(['reminded_at' => now()])->save();
            });

        return $sent;
    }
}
