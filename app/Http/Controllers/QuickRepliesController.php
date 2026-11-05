<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\QuickReply;
use App\Services\Chat\QuickReplies;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Canned answers shared by the team: the management page and the list the chat opens when someone types "/". */
class QuickRepliesController extends Controller
{
    private const LIMIT = 200;

    public function __construct(private QuickReplies $replies)
    {
    }

    public function index(Request $request)
    {
        return view('replies.index', ['replies' => QuickReply::where('user_id', $request->user()->id)->orderBy('shortcut')->get(), 'variables' => QuickReplies::VARIABLES]);
    }

    /** JSON for the chat: matching replies, already filled in for the open conversation. */
    public function search(Request $request)
    {
        $owner = $request->user();
        $q = QuickReply::cleanShortcut((string) $request->query('q', ''));
        $chat = $request->filled('chat') ? Chat::with('contact')->where('user_id', $owner->id)->find((int) $request->query('chat')) : null;
        $agent = TeamContext::actor($request);

        $items = QuickReply::where('user_id', $owner->id)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('shortcut', 'like', $q . '%')->orWhere('shortcut', 'like', '%' . $q . '%')->orWhere('title', 'like', '%' . $q . '%')))
            ->orderByRaw('shortcut like ? desc', [$q . '%'])->orderByDesc('uses')->orderBy('shortcut')->limit(8)->get();

        return response()->json(['items' => $items->map(fn ($r) => [
            'id' => $r->id, 'shortcut' => $r->shortcut, 'title' => $r->title, 'text' => $this->replies->render($r, $chat, $agent, $owner),
        ])->values()]);
    }

    /** The chat reports that a reply was used (popularity ordering). */
    public function used(Request $request, int $reply)
    {
        QuickReply::where('user_id', $request->user()->id)->whereKey($reply)->increment('uses');

        return response()->json(['ok' => true]);
    }

    public function store(Request $request)
    {
        $owner = $request->user();
        abort_if(QuickReply::where('user_id', $owner->id)->count() >= self::LIMIT, 422, __('Quick reply limit reached.'));
        $data = $this->validated($request, null);
        QuickReply::create($data + ['user_id' => $owner->id, 'created_by' => TeamContext::actor($request)->id]);

        return redirect()->route('replies.index')->with('success', __('Quick reply saved.'));
    }

    public function update(Request $request, int $reply)
    {
        $r = QuickReply::where('user_id', $request->user()->id)->findOrFail($reply);
        $r->update($this->validated($request, $r));

        return redirect()->route('replies.index')->with('success', __('Quick reply saved.'));
    }

    public function destroy(Request $request, int $reply)
    {
        QuickReply::where('user_id', $request->user()->id)->findOrFail($reply)->delete();

        return redirect()->route('replies.index')->with('success', __('Quick reply removed.'));
    }

    private function validated(Request $request, ?QuickReply $current): array
    {
        $request->merge(['shortcut' => QuickReply::cleanShortcut((string) $request->input('shortcut'))]);

        return $request->validate([
            'shortcut' => ['required', 'string', 'max:40', Rule::unique('quick_replies', 'shortcut')->where('user_id', $request->user()->id)->ignore($current?->id)],
            'title' => 'required|string|max:80',
            'body' => 'required|string|max:4000',
        ]);
    }
}
