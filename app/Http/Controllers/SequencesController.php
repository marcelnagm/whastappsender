<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Services\Chat\Sequences;
use App\Services\Team\TeamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Follow-up sequences: management page (automations permission) and enrolling from the chat. */
class SequencesController extends Controller
{
    public function __construct(private Sequences $svc)
    {
    }

    public function index(Request $request)
    {
        $owner = $request->user();
        $sequences = Sequence::with('steps')->withCount(['enrollments as running' => fn ($q) => $q->where('status', 'active')])->where('user_id', $owner->id)->orderBy('name')->get();
        $stats = SequenceEnrollment::where('user_id', $owner->id)->selectRaw('status, stop_reason, count(*) as n')->groupBy('status', 'stop_reason')->get();

        return view('sequences.index', compact('sequences', 'stats'));
    }

    public function store(Request $request)
    {
        $this->svc->save($request->user(), null, ...$this->input($request));

        return back()->with('success', __('Sequence saved.'));
    }

    public function update(Request $request, int $sequence)
    {
        $seq = Sequence::where('user_id', $request->user()->id)->findOrFail($sequence);
        $this->svc->save($request->user(), $seq, ...$this->input($request));

        return back()->with('success', __('Sequence saved.'));
    }

    public function destroy(Request $request, int $sequence)
    {
        $seq = Sequence::where('user_id', $request->user()->id)->findOrFail($sequence);
        SequenceEnrollment::where('sequence_id', $seq->id)->where('status', 'active')->update(['status' => 'stopped', 'stop_reason' => 'removed', 'next_at' => null]);
        $seq->steps()->delete();
        $seq->delete();

        return back()->with('success', __('Sequence removed.'));
    }

    /** @return array{0:string,1:bool,2:array} */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'steps' => 'required|array|max:' . Sequences::MAX_STEPS,
            'steps.*.delay_minutes' => 'required|integer|min:1',
            'steps.*.body' => 'nullable|string|max:4096',
        ]);

        return [$data['name'], $request->boolean('active', true), $data['steps']];
    }

    // ---- chat side (JSON) ----

    public function enroll(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($request, $chat);
        $data = $request->validate(['sequence_id' => 'required|integer']);
        $seq = Sequence::where('user_id', $chat->user_id)->findOrFail($data['sequence_id']);
        $this->svc->enroll($chat, $seq, TeamContext::actor($request));

        return response()->json(['sequences' => self::forChat($chat)], 201);
    }

    public function stop(Request $request, Chat $chat, int $enrollment): JsonResponse
    {
        $this->authorizeChat($request, $chat);
        $this->svc->stop(SequenceEnrollment::where('chat_id', $chat->id)->where('user_id', $chat->user_id)->findOrFail($enrollment), 'manual');

        return response()->json(['sequences' => self::forChat($chat)]);
    }

    /** What the chat panel needs: sequences that can be started and the ones running here. */
    public static function forChat(Chat $chat): array
    {
        $running = SequenceEnrollment::with('sequence:id,name')->where('chat_id', $chat->id)->where('status', 'active')->get();

        return [
            'available' => Sequence::where('user_id', $chat->user_id)->where('active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'running' => $running->map(fn ($e) => ['id' => $e->id, 'name' => optional($e->sequence)->name, 'next' => optional($e->next_at)->timezone(config('app.timezone'))->format('d/m H:i'), 'step' => $e->next_position])->values()->all(),
        ];
    }

    private function authorizeChat(Request $request, Chat $chat): void
    {
        abort_unless((int) $chat->user_id === (int) $request->user()->id, 403);
        $only = app(\App\Services\Chat\ChatAssignment::class)->restrictedTo($request->user());
        abort_if($only && $chat->assigned_to && (int) $chat->assigned_to !== $only, 403);
    }
}
