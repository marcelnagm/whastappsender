<?php

namespace App\Http\Controllers;

use App\Models\TeamGoal;
use App\Services\Chat\ChatAssignment;
use App\Services\Reports\TeamReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Performance of each person of the team and the monthly goals. */
class TeamReportController extends Controller
{
    public function __construct(private TeamReport $report, private ChatAssignment $team)
    {
    }

    public function index(Request $request)
    {
        $owner = $request->user();
        [$from, $to, $period] = $this->period($request);

        return view('team-report.index', [
            'data' => $this->report->build($owner, $from, $to),
            'goals' => $this->report->goals($owner),
            'period' => $period,
            'metrics' => TeamGoal::METRICS,
            'people' => $this->team->people($owner),
        ]);
    }

    public function storeGoal(Request $request)
    {
        $owner = $request->user();
        $data = $request->validate([
            'metric' => ['required', Rule::in(TeamGoal::METRICS)],
            'member_id' => 'nullable|integer|min:0',
            'target' => 'required|numeric|min:0.01|max:99999999',
        ]);
        $member = (int) ($data['member_id'] ?? 0);
        if ($member && !$this->team->people($owner)->contains('id', $member)) {
            return back()->withErrors(['target' => __('This person is not part of the team.')]);
        }
        TeamGoal::updateOrCreate(['user_id' => $owner->id, 'member_id' => $member, 'metric' => $data['metric']], ['target' => $data['target']]);

        return back()->with('success', __('Goal saved.'));
    }

    public function destroyGoal(Request $request, int $goal)
    {
        TeamGoal::where('user_id', $request->user()->id)->findOrFail($goal)->delete();

        return back()->with('success', __('Goal removed.'));
    }

    /** @return array{0:Carbon,1:Carbon,2:string} */
    private function period(Request $request): array
    {
        $p = (string) $request->query('period', '30');
        if ($p === 'month') {
            return [now()->startOfMonth(), now()->endOfDay(), 'month'];
        }
        if ($p === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from'))->startOfDay();
                $to = Carbon::parse((string) $request->query('to'))->endOfDay();
                if ($from->lte($to) && $from->diffInDays($to) <= 366) {
                    return [$from, $to, 'custom'];
                }
            } catch (\Throwable $e) {
                // falls through to the default
            }
        }
        $days = in_array($p, ['7', '30', '90'], true) ? (int) $p : 30;

        return [now()->subDays($days - 1)->startOfDay(), now()->endOfDay(), (string) $days];
    }
}
