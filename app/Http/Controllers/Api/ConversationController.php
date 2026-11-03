<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ChatController;
use App\Models\Chat;
use App\Models\Instance;
use App\Services\Channels\ReplyWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Omnichannel inbox over the API: every conversation of the user, whatever the channel. */
class ConversationController extends ApiController
{
    public function index(Request $request)
    {
        $q = trim((string) $request->input('search', ''));
        $digits = preg_replace('/\D/', '', $q);
        $channel = $request->input('channel');

        $query = Chat::with(['contact', 'instance'])
            ->where('user_id', Auth::id())->where('archived', false)->whereNotNull('last_message_at')
            ->when($request->filled('instance_id'), fn ($w) => $w->where('instance_id', (int) $request->input('instance_id')))
            ->when(in_array($channel, ['whatsapp', 'telegram', 'instagram', 'facebook', 'tiktok', 'email'], true), function ($w) use ($channel) {
                $ids = Instance::where('user_id', Auth::id())
                    ->when($channel === 'whatsapp', fn ($i) => $i->where(fn ($x) => $x->whereNull('channel')->orWhere('channel', 'whatsapp')), fn ($i) => $i->where('channel', $channel))
                    ->pluck('id');
                $w->whereIn('instance_id', $ids);
            })
            ->when($request->boolean('unread'), fn ($w) => $w->where('unread_count', '>', 0))
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q, $digits) {
                $x->where('name', 'like', "%{$q}%");
                if ($digits !== '') {
                    $x->orWhere('phone', 'like', "%{$digits}%");
                }
                $x->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('last_message_at');

        $page = $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        $rows = collect($page->items())->map(fn (Chat $c) => [
            'id' => $c->id,
            'name' => $c->displayName(),
            'phone' => $c->phone,
            'channel' => $c->instance ? $c->instance->channelKey() : 'whatsapp',
            'instance_id' => $c->instance_id,
            'contact_id' => $c->contact_id,
            'unread' => (int) $c->unread_count,
            'last_message' => $c->last_message_preview,
            'last_message_from_me' => (bool) $c->last_message_from_me,
            'last_message_at' => optional($c->last_message_at)->toIso8601String(),
            'window_open' => ReplyWindow::isOpen($c),
        ]);

        return response()->json([
            'success' => true, 'message' => 'OK', 'data' => $rows,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function messages(Request $request, Chat $chat)
    {
        // the chat controller reads the query string; tools and JSON bodies arrive as input
        $request->query->add(array_filter($request->only(['before_id', 'since']), fn ($v) => $v !== null && $v !== ''));

        return $this->wrap(app(ChatController::class)->messages($request, $chat));
    }

    public function send(Request $request, Chat $chat)
    {
        $request->validate(['text' => 'required|string|max:4096']);

        return $this->wrap(app(ChatController::class)->send($request, $chat), 201);
    }

    public function markRead(Chat $chat)
    {
        return $this->wrap(app(ChatController::class)->markRead($chat));
    }

    public function aiPause(Request $request, Chat $chat)
    {
        return $this->wrap(app(ChatController::class)->aiPause($request, $chat));
    }

    /** Re-shapes the chat controller's JSON into the API envelope; errors keep their status code. */
    private function wrap(JsonResponse $r, int $ok = 200): JsonResponse
    {
        $data = $r->getData(true);

        return $r->getStatusCode() >= 400
            ? $this->error($data['message'] ?? 'Request failed.', $r->getStatusCode())
            : $this->success($data, 'OK', $r->getStatusCode() ?: $ok);
    }
}
