<?php

namespace App\Http\Controllers;

use App\Models\SlaEvent;
use App\Models\SlaSetting;
use App\Services\Chat\ChatAssignment;
use App\Services\Sla\SlaMonitor;
use Illuminate\Http\Request;

/** First-response SLA: settings, live queue of waiting customers and breach history. */
class SlaController extends Controller
{
    public function index(Request $request)
    {
        $owner = $request->user();
        $cfg = SlaSetting::for($owner->id);
        $monitor = app(SlaMonitor::class);
        $now = now();

        $queue = collect($monitor->waiting($owner->id))->map(function ($r) use ($cfg, $monitor, $now) {
            $r['waited'] = $monitor->waitedMinutes($cfg, $r['since'], $now);
            $r['state'] = $r['waited'] >= $cfg->first_response_minutes + $cfg->escalate_after_minutes ? 'escalated' : ($r['waited'] >= $cfg->first_response_minutes ? 'overdue' : 'ok');

            return $r;
        })->sortByDesc('waited')->values();

        $since = now()->subDays(30);
        $breaches = SlaEvent::where('user_id', $owner->id)->where('kind', 'breach')->where('created_at', '>=', $since)->count();
        $escalations = SlaEvent::where('user_id', $owner->id)->where('kind', 'escalation')->where('created_at', '>=', $since)->count();
        $byAgent = SlaEvent::where('user_id', $owner->id)->where('kind', 'breach')->where('created_at', '>=', $since)->selectRaw('assigned_to, count(*) as n')->groupBy('assigned_to')->pluck('n', 'assigned_to');
        $people = app(ChatAssignment::class)->people($owner);
        $recent = SlaEvent::with('chat')->where('user_id', $owner->id)->where('kind', 'breach')->latest('id')->limit(15)->get();

        return view('sla.index', compact('cfg', 'queue', 'breaches', 'escalations', 'byAgent', 'people', 'recent'));
    }

    public function update(Request $request)
    {
        $owner = $request->user();
        $data = $request->validate([
            'first_response_minutes' => 'required|integer|min:1|max:1440',
            'escalate_after_minutes' => 'required|integer|min:1|max:1440',
            'escalate_to' => 'nullable|integer',
        ]);
        $to = $data['escalate_to'] ?? null;
        if ($to && !app(ChatAssignment::class)->people($owner)->contains('id', (int) $to)) {
            return back()->withErrors(['escalate_to' => __('Choose a person of the team.')])->withInput();
        }
        SlaSetting::updateOrCreate(['user_id' => $owner->id], [
            'enabled' => $request->boolean('enabled'),
            'first_response_minutes' => $data['first_response_minutes'],
            'escalate_after_minutes' => $data['escalate_after_minutes'],
            'escalate_to' => $to ?: null,
            'reassign' => $request->boolean('reassign'),
            'business_only' => $request->boolean('business_only'),
        ]);

        return back()->with('success', __('SLA saved.'));
    }
}
