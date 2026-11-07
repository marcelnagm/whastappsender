<?php

namespace App\Http\Controllers;

use App\Models\CsatSetting;
use App\Models\CsatSurvey;
use App\Services\Chat\ChatAssignment;
use App\Services\Chat\Csat;
use Illuminate\Http\Request;

/** Satisfaction survey (CSAT 1-5 or NPS 0-10) sent when a conversation is closed. */
class CsatController extends Controller
{
    public function index(Request $request)
    {
        $owner = $request->user();
        $cfg = CsatSetting::for($owner->id);
        $stats = Csat::stats($owner->id, $cfg->scale);
        $people = app(ChatAssignment::class)->people($owner)->keyBy('id');
        $recent = CsatSurvey::with('chat:id,name,phone')->where('user_id', $owner->id)->where('status', 'answered')->latest('id')->limit(20)->get();

        return view('csat.index', compact('cfg', 'stats', 'people', 'recent'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'scale' => 'required|in:csat,nps',
            'question' => 'nullable|string|max:500',
            'thanks' => 'nullable|string|max:500',
            'expire_hours' => 'required|integer|min:1|max:168',
        ]);
        CsatSetting::updateOrCreate(['user_id' => $request->user()->id], [
            'enabled' => $request->boolean('enabled'),
            'scale' => $data['scale'],
            'question' => $data['question'] ?? null,
            'thanks' => $data['thanks'] ?? null,
            'expire_hours' => $data['expire_hours'],
        ]);

        return back()->with('success', __('Survey saved.'));
    }
}
