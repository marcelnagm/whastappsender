<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;

/** Log of calls, meetings and visits with a person (shown in their timeline). */
class ActivitiesController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'contact_id' => 'required|integer', 'type' => 'required|in:' . implode(',', array_keys(Activity::TYPES)),
            'outcome' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000',
            'duration_min' => 'nullable|integer|min:1|max:1440', 'occurred_at' => 'nullable|date|before_or_equal:now +5 minutes',
        ]);
        $owner = $request->user();
        $contact = Contact::where('user_id', $owner->id)->findOrFail($data['contact_id']);
        $deal = Deal::where('user_id', $owner->id)->where('contact_id', $contact->id)->where('status', 'open')->latest('id')->first();

        Activity::create([
            'user_id' => $owner->id, 'contact_id' => $contact->id, 'deal_id' => $deal?->id, 'type' => $data['type'],
            'outcome' => $data['outcome'] ?? null, 'notes' => $data['notes'] ?? null, 'duration_min' => $data['duration_min'] ?? null,
            'occurred_at' => !empty($data['occurred_at']) ? $data['occurred_at'] : now(), 'created_by' => TeamContext::actor($request)->id ?? $owner->id,
        ]);
        if ($deal) {
            Deal::whereKey($deal->id)->touch();   // a call counts as sign of life for stalled-deal alerts
        }

        return back()->with('success', __('Activity logged.'));
    }

    public function destroy(Request $request, int $activity)
    {
        Activity::where('user_id', $request->user()->id)->whereKey($activity)->delete();

        return back()->with('success', __('Deleted.'));
    }
}
