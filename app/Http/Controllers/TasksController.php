<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Task;
use App\Services\Chat\ChatAssignment;
use App\Services\Tasks\FollowUps;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;

/** Follow-up tasks: what each person has to do next, with a due time and a reminder. */
class TasksController extends Controller
{
    public function __construct(private FollowUps $tasks, private ChatAssignment $team)
    {
    }

    public function index(Request $request)
    {
        $owner = $request->user();
        $me = TeamContext::actor($request);
        $scope = $request->query('scope', app(TeamContext::class)->isMember() ? 'mine' : 'all');
        $tab = $request->query('tab', 'open');

        $q = Task::with(['contact:id,name,contact', 'assignee:id,name'])->where('user_id', $owner->id)
            ->when($scope === 'mine', fn ($w) => $w->where('assigned_to', $me->id));
        $counts = [
            'open' => (clone $q)->whereNull('done_at')->count(),
            'overdue' => (clone $q)->whereNull('done_at')->where('due_at', '<', now())->count(),
            'today' => (clone $q)->whereNull('done_at')->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            'done' => (clone $q)->whereNotNull('done_at')->count(),
        ];
        $list = match ($tab) {
            'overdue' => $q->whereNull('done_at')->where('due_at', '<', now())->orderBy('due_at'),
            'today' => $q->whereNull('done_at')->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->orderBy('due_at'),
            'done' => $q->whereNotNull('done_at')->orderByDesc('done_at'),
            default => $q->whereNull('done_at')->orderByRaw('due_at is null')->orderBy('due_at'),
        };

        return view('tasks.index', [
            'tasks' => $list->limit(200)->get(), 'tab' => $tab, 'scope' => $scope, 'counts' => $counts, 'me' => $me,
            'people' => $this->team->people($owner),
            'contacts' => Contact::where('user_id', $owner->id)->orderBy('name')->limit(300)->get(['id', 'name', 'contact']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:160', 'notes' => 'nullable|string|max:2000', 'due_at' => 'nullable|date',
            'assigned_to' => 'nullable|integer', 'contact_id' => 'nullable|integer', 'chat_id' => 'nullable|integer', 'deal_id' => 'nullable|integer',
            'recurrence' => 'nullable|in:daily,weekly,monthly',
        ]);
        $task = $this->tasks->create($request->user(), TeamContext::actor($request), $data);

        return $request->expectsJson()
            ? response()->json(['id' => $task->id])
            : redirect()->route('tasks.index')->with('success', __('Task created.'));
    }

    public function update(Request $request, int $task)
    {
        $t = $this->owned($request, $task);
        $data = $request->validate(['title' => 'required|string|max:160', 'notes' => 'nullable|string|max:2000', 'due_at' => 'nullable|date', 'assigned_to' => 'nullable|integer']);
        if (!empty($data['assigned_to'])) {
            abort_unless($this->team->people($request->user())->contains('id', (int) $data['assigned_to']), 422, __('This person is not part of the team.'));
            $t->assigned_to = (int) $data['assigned_to'];
        }
        $t->fill(['title' => $data['title'], 'notes' => $data['notes'] ?? null])->save();
        $this->tasks->reschedule($t, $data['due_at'] ?? null);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', __('Task saved.'));
    }

    public function done(Request $request, int $task)
    {
        $t = $this->tasks->toggle($this->owned($request, $task), TeamContext::actor($request));

        return $request->expectsJson() ? response()->json(['done' => $t->isDone()]) : back();
    }

    public function destroy(Request $request, int $task)
    {
        $this->owned($request, $task)->delete();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', __('Task removed.'));
    }

    private function owned(Request $request, int $id): Task
    {
        return Task::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
