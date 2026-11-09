<?php

namespace App\Http\Controllers;

use App\Models\InsightReport;
use App\Services\AI\Llm\LlmException;
use App\Services\Insights\InsightGenerator;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;

/** Reports the AI writes about the business, drawn from validated blocks. */
class InsightsController extends Controller
{
    public function index(Request $request, InsightGenerator $gen)
    {
        $uid = $request->user()->id;
        $reports = InsightReport::where('user_id', $uid)->orderByDesc('id')->limit(20)->get(['id', 'business_type', 'period_days', 'created_at', 'status']);
        $report = $request->filled('r') ? InsightReport::where('user_id', $uid)->find((int) $request->query('r')) : InsightReport::where('user_id', $uid)->latest('id')->first();

        return view('ai.insights', ['reports' => $reports, 'report' => $report, 'types' => InsightGenerator::types(),
            'ready' => (bool) $gen->profileFor($request->user()), 'lastType' => $report->business_type ?? 'general']);
    }

    public function generate(Request $request, InsightGenerator $gen)
    {
        $d = $request->validate(['business_type' => 'required|string|max:20', 'days' => 'required|integer|in:7,30,90,365']);
        try {
            $report = $gen->generate($request->user(), $d['business_type'], (int) $d['days'], TeamContext::actor($request)->id ?? null);
        } catch (LlmException $e) {
            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.insights.index', ['r' => $report->id])->with('success', $report->status === 'ok' ? __('Report generated.') : __('Report generated, but the AI did not follow the format, so it is shown as plain text.'));
    }

    public function destroy(Request $request, int $report)
    {
        InsightReport::where('user_id', $request->user()->id)->whereKey($report)->delete();

        return redirect()->route('ai.insights.index')->with('success', __('Deleted.'));
    }
}
