<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\InsightReport;
use App\Models\InsightSchedule;
use App\Services\AI\AiUsageLog;
use App\Services\Billing\Entitlements;
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

        $feedback = [
            'up' => ChatMessage::where('user_id', $uid)->where('source', 'ai')->where('ai_feedback', 1)->where('updated_at', '>=', now()->subDays(30))->count(),
            'down' => ChatMessage::where('user_id', $uid)->where('source', 'ai')->where('ai_feedback', -1)->where('updated_at', '>=', now()->subDays(30))->count(),
            'bad' => ChatMessage::where('user_id', $uid)->where('source', 'ai')->where('ai_feedback', -1)->orderByDesc('updated_at')->limit(8)->get(['id', 'chat_id', 'body', 'ai_feedback_note', 'updated_at']),
        ];
        $ent = app(Entitlements::class);

        return view('ai.insights', ['usage' => AiUsageLog::month($uid), 'feedback' => $feedback, 'schedule' => InsightSchedule::where('user_id', $uid)->first(),
            'quota' => ['used' => $ent->used($request->user(), 'insights_per_month'), 'limit' => $ent->limit($request->user(), 'insights_per_month')],'reports' => $reports, 'report' => $report, 'types' => InsightGenerator::types(),
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

    /** Turns the automatic report on/off and sets how often it goes out. */
    public function schedule(Request $request)
    {
        $d = $request->validate(['enabled' => 'nullable|boolean', 'frequency' => 'required|in:weekly,monthly', 'business_type' => 'required|string|max:20', 'days' => 'required|integer|in:7,30,90,365']);
        InsightSchedule::updateOrCreate(['user_id' => $request->user()->id], ['enabled' => (bool) ($d['enabled'] ?? false), 'frequency' => $d['frequency'],
            'business_type' => isset(InsightGenerator::types()[$d['business_type']]) ? $d['business_type'] : 'general', 'period_days' => $d['days'], 'last_error' => null]);

        return redirect()->route('ai.insights.index')->with('success', __('Schedule saved.'));
    }

    public function destroy(Request $request, int $report)
    {
        InsightReport::where('user_id', $request->user()->id)->whereKey($report)->delete();

        return redirect()->route('ai.insights.index')->with('success', __('Deleted.'));
    }
}
