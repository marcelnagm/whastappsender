<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Instance;
use App\Models\TypebotSession;
use App\Models\TypebotSetting;
use App\Models\User;
use App\Services\Bots\TypebotBot;
use App\Services\Bots\TypebotClient;
use App\Services\Chat\ChatAssignment;
use App\Services\Webhooks\OutboundWebhooks;
use Illuminate\Http\Request;

/** Typebot: connect a published flow to the conversations and receive the hand-over to a person. */
class TypebotController extends Controller
{
    public function index(Request $request)
    {
        $owner = $request->user();
        $cfg = TypebotSetting::for($owner->id);
        $instances = Instance::where('user_id', $owner->id)->orderBy('name')->get(['id', 'name']);
        $people = app(ChatAssignment::class)->people($owner);
        $stats = TypebotSession::where('user_id', $owner->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('typebot.index', compact('cfg', 'instances', 'people', 'stats'));
    }

    public function update(Request $request)
    {
        $owner = $request->user();
        $data = $request->validate([
            'base_url' => 'required|url|max:200', 'public_id' => 'required|string|max:120|regex:/^[A-Za-z0-9._-]+$/',
            'instance_id' => 'nullable|integer', 'handoff_keywords' => 'nullable|string|max:255', 'on_finish' => 'required|in:none,handoff',
            'handoff_message' => 'nullable|string|max:1000', 'handoff_to' => 'nullable|integer', 'expire_minutes' => 'required|integer|min:5|max:10080',
        ]);
        // local development talks to the Docker Typebot (http://localhost:8084); everywhere else it must be public https (no SSRF)
        if (!app()->environment('local') && !OutboundWebhooks::urlAllowed($data['base_url'])) {
            return back()->withErrors(['base_url' => __('Use a public https address.')])->withInput();
        }
        if (!empty($data['instance_id']) && !Instance::where('user_id', $owner->id)->whereKey($data['instance_id'])->exists()) {
            return back()->withErrors(['instance_id' => __('Choose a number of your account.')])->withInput();
        }
        if (!empty($data['handoff_to']) && !app(ChatAssignment::class)->people($owner)->contains('id', (int) $data['handoff_to'])) {
            return back()->withErrors(['handoff_to' => __('Choose a person of the team.')])->withInput();
        }
        TypebotSetting::updateOrCreate(['user_id' => $owner->id], [
            'enabled' => $request->boolean('enabled'), 'only_outside_hours' => $request->boolean('only_outside_hours'),
            'base_url' => rtrim($data['base_url'], '/'), 'public_id' => $data['public_id'], 'instance_id' => ($data['instance_id'] ?? null) ?: null,
            'handoff_keywords' => $data['handoff_keywords'] ?? null, 'on_finish' => $data['on_finish'], 'handoff_message' => $data['handoff_message'] ?? null,
            'handoff_to' => ($data['handoff_to'] ?? null) ?: null, 'expire_minutes' => $data['expire_minutes'],
        ]);

        return back()->with('success', __('Saved.'));
    }

    /** Registers a session without running the flow: proves that the address and the public id are right. */
    public function test(Request $request)
    {
        $cfg = TypebotSetting::for($request->user()->id);
        if (!$cfg->public_id) {
            return back()->with('error', __('Save the public id first.'));
        }
        try {
            $res = app(TypebotClient::class)->start($cfg, [], true);

            return back()->with('success', __('Connected to Typebot.') . (!empty($res['sessionId']) ? '' : ''));
        } catch (\Throwable $e) {
            return back()->with('error', __('Could not reach the flow: :e', ['e' => mb_substr($e->getMessage(), 0, 160)]));
        }
    }

    /** Called by the flow itself. Only the owner of the conversation can be affected and only a signed address is accepted. */
    public function handoff(Request $request, int $chat, string $sig)
    {
        abort_unless(hash_equals(TypebotBot::signature($chat), $sig), 403);
        $c = Chat::findOrFail($chat);
        $owner = User::findOrFail($c->user_id);
        $note = $request->input('note');
        app(TypebotBot::class)->handoff($c, $owner, __('The flow asked for a person.'), is_string($note) ? $note : null);

        return response()->json(['ok' => true]);
    }
}
