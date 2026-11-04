<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatNote;
use App\Models\ChatTag;
use App\Models\Pipeline;
use App\Services\Chat\ChatAssignment;
use App\Services\Pipeline\LeadDeals;
use App\Services\Team\TeamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The team side of the chat: who handles a conversation, labels, internal notes, deal of the conversation and the account's inbox settings. */
class ChatCrmController extends Controller
{
    public function __construct(private ChatAssignment $assignment, private LeadDeals $deals, private TeamContext $team)
    {
    }

    public function show(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);
        $owner = Auth::user();
        $me = TeamContext::actor($request);
        $chat->load(['tags', 'assignee:id,name']);

        $deal = $this->deals->openDealFor($chat);
        $canDeals = $this->team->can('deals');

        return response()->json([
            'assigned' => $chat->assigned_to ? ['id' => (int) $chat->assigned_to, 'name' => optional($chat->assignee)->name] : null,
            'people' => $this->assignment->people($owner)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name . ($u->id === $owner->id ? ' (' . __('owner') . ')' : '')])->values(),
            'me' => (int) $me->id,
            'tags' => $chat->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values(),
            'all_tags' => ChatTag::where('user_id', $owner->id)->orderBy('name')->get(['id', 'name', 'color']),
            'notes' => ChatNote::with('author:id,name')->where('chat_id', $chat->id)->orderByDesc('id')->limit(50)->get()->map(fn ($n) => [
                'id' => $n->id, 'body' => $n->body, 'author' => optional($n->author)->name ?: __('Team'), 'at' => $n->created_at->toIso8601String(),
                'mine' => (int) $n->author_id === (int) $me->id,
            ])->values(),
            'can_deals' => $canDeals,
            'deal' => $deal && $canDeals ? ['id' => $deal->id, 'title' => $deal->title, 'stage' => optional($deal->stage)->name, 'url' => route('pipeline.index', ['pipeline' => $deal->pipeline_id])] : null,
            'pipelines' => $canDeals ? Pipeline::where('user_id', $owner->id)->orderBy('id')->get(['id', 'name']) : [],
            'settings' => $this->team->can('team') ? [
                'assign_mode' => $owner->chat_assign_mode, 'agents_see' => $owner->chat_agents_see,
                'deal_auto_chat' => (bool) $owner->deal_auto_chat, 'deal_auto_form' => (bool) $owner->deal_auto_form,
            ] : null,
        ]);
    }

    public function assign(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);
        $data = $request->validate(['user_id' => 'nullable|integer']);
        $this->assignment->assign($chat, $data['user_id'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function syncTags(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);
        $data = $request->validate(['tag_ids' => 'array|max:20', 'tag_ids.*' => 'integer']);
        $ids = ChatTag::where('user_id', Auth::id())->whereIn('id', $data['tag_ids'] ?? [])->pluck('id')->all();
        $chat->tags()->sync($ids);

        return response()->json(['ok' => true]);
    }

    public function storeTag(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:40', 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $name = trim(preg_replace('/\s+/', ' ', $data['name']));
        abort_if($name === '', 422);
        $count = ChatTag::where('user_id', Auth::id())->count();
        $tag = ChatTag::where('user_id', Auth::id())->where('name', $name)->first();
        if (!$tag) {
            abort_if($count >= 50, 422, __('Tag limit reached.'));
            $tag = ChatTag::create(['user_id' => Auth::id(), 'name' => $name, 'color' => $data['color'] ?? '#0d6efd']);
        }

        return response()->json(['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color]);
    }

    public function destroyTag(ChatTag $tag): JsonResponse
    {
        abort_unless((int) $tag->user_id === (int) Auth::id() && $this->team->can('team'), 403);
        \Illuminate\Support\Facades\DB::table('chat_tag_links')->where('tag_id', $tag->id)->delete();
        $tag->delete();

        return response()->json(['ok' => true]);
    }

    public function storeNote(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);
        $data = $request->validate(['body' => 'required|string|max:4000']);
        $note = ChatNote::create(['user_id' => Auth::id(), 'chat_id' => $chat->id, 'author_id' => TeamContext::actor($request)->id, 'body' => trim($data['body'])]);

        return response()->json(['id' => $note->id]);
    }

    public function destroyNote(Request $request, ChatNote $note): JsonResponse
    {
        abort_unless((int) $note->user_id === (int) Auth::id(), 403);
        abort_unless((int) $note->author_id === (int) TeamContext::actor($request)->id || $this->team->can('team'), 403);
        $note->delete();

        return response()->json(['ok' => true]);
    }

    public function createDeal(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);
        abort_unless($this->team->can('deals'), 403);
        $data = $request->validate(['pipeline_id' => 'nullable|integer']);
        $pipeline = !empty($data['pipeline_id']) ? Pipeline::where('user_id', Auth::id())->findOrFail($data['pipeline_id']) : null;
        $deal = $this->deals->manual($chat->load('contact'), Auth::user(), $pipeline);
        abort_if(!$deal, 422, __('This person already has an open deal.'));

        return response()->json(['id' => $deal->id, 'url' => route('pipeline.index', ['pipeline' => $deal->pipeline_id])]);
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless($this->team->can('team'), 403);
        $data = $request->validate([
            'assign_mode' => 'required|in:manual,balanced', 'agents_see' => 'required|in:all,assigned',
            'deal_auto_chat' => 'required|boolean', 'deal_auto_form' => 'required|boolean',
        ]);
        Auth::user()->forceFill([
            'chat_assign_mode' => $data['assign_mode'], 'chat_agents_see' => $data['agents_see'],
            'deal_auto_chat' => $data['deal_auto_chat'], 'deal_auto_form' => $data['deal_auto_form'],
        ])->save();

        return response()->json(['ok' => true]);
    }

    private function authorizeChat(Chat $chat): void
    {
        abort_unless((int) $chat->user_id === (int) Auth::id(), 403);
        $only = $this->assignment->restrictedTo(Auth::user());
        abort_if($only && $chat->assigned_to && (int) $chat->assigned_to !== $only, 403);
    }
}
