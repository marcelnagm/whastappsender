<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ScheduledMessage;
use App\Services\Chat\ScheduledMessages;
use App\Services\Team\TeamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Scheduled messages of one conversation (JSON, used by the chat screen). */
class ScheduledMessagesController extends Controller
{
    public function __construct(private ScheduledMessages $svc)
    {
    }

    public function store(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($request, $chat);
        $data = $request->validate(['body' => 'required|string|max:4096', 'send_at' => 'required|date', 'cancel_on_reply' => 'nullable|boolean']);
        // the browser sends the local time of the person; the account's timezone is the app's
        $at = Carbon::parse($data['send_at'], config('app.timezone'));
        $this->svc->schedule($chat, TeamContext::actor($request), $data['body'], $at, $request->boolean('cancel_on_reply', true));

        return response()->json(['scheduled' => $this->list($chat)], 201);
    }

    public function destroy(Request $request, Chat $chat, int $scheduled): JsonResponse
    {
        $this->authorizeChat($request, $chat);
        $this->svc->cancel(ScheduledMessage::where('chat_id', $chat->id)->where('user_id', $chat->user_id)->findOrFail($scheduled));

        return response()->json(['scheduled' => $this->list($chat)]);
    }

    public static function list(Chat $chat): array
    {
        return ScheduledMessage::where('chat_id', $chat->id)->where('status', 'pending')->orderBy('send_at')->limit(20)->get()
            ->map(fn ($m) => ['id' => $m->id, 'body' => mb_substr($m->body, 0, 140), 'at' => $m->send_at->timezone(config('app.timezone'))->format('d/m H:i'), 'cancel_on_reply' => $m->cancel_on_reply])->all();
    }

    /** Same visibility rule as the chat itself (an agent only reaches what the account lets him see). */
    private function authorizeChat(Request $request, Chat $chat): void
    {
        abort_unless((int) $chat->user_id === (int) $request->user()->id, 403);
        $only = app(\App\Services\Chat\ChatAssignment::class)->restrictedTo($request->user());
        abort_if($only && $chat->assigned_to && (int) $chat->assigned_to !== $only, 403);
    }
}
