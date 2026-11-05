<?php

namespace App\Services\Reports;

use App\Models\ChatMessage;
use App\Models\Deal;
use App\Models\Task;
use App\Models\TeamGoal;
use App\Models\User;
use App\Services\Chat\ChatAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Performance per person of the team: how fast customers get a first answer, how many conversations were answered,
 * deals won / lost and follow-ups, plus progress against the monthly goals.
 *
 * First response: a customer message that starts a wait (nobody had answered the previous one) is closed by the next
 * outgoing message. A human reply is credited to whoever sent it (or, when it was typed on the phone, to the person the
 * conversation is assigned to); a reply by the AI agent or a campaign closes the wait without counting for anyone.
 */
class TeamReport
{
    public const UNASSIGNED = 0;

    /** Messages looked at per report: protects the server on very large accounts. */
    private const MAX_MESSAGES = 300000;

    public function __construct(private ChatAssignment $team)
    {
    }

    /** @return array{people:array<int,array>,totals:array,ai_answered:int,from:Carbon,to:Carbon} */
    public function build(User $owner, Carbon $from, Carbon $to): array
    {
        $rows = [];
        foreach ($this->team->people($owner) as $p) {
            $rows[$p->id] = $this->blank((int) $p->id, $p->name);
        }
        $rows[self::UNASSIGNED] = $this->blank(self::UNASSIGNED, __('Unassigned'));

        $assigned = DB::table('chats')->where('user_id', $owner->id)->pluck('assigned_to', 'id');
        $waits = [];
        $times = [];
        $aiAnswered = 0;
        $seen = 0;

        $q = ChatMessage::query()->where('user_id', $owner->id)->where('deleted', false)
            ->where(fn ($w) => $w->whereBetween('message_timestamp', [$from, $to])->orWhere(fn ($x) => $x->whereNull('message_timestamp')->whereBetween('created_at', [$from, $to])))
            ->orderBy('chat_id')->orderBy('id');

        foreach ($q->cursor() as $m) {
            if (++$seen > self::MAX_MESSAGES) {
                break;
            }
            $ts = ($m->message_timestamp ?? $m->created_at)->timestamp;
            if (!$m->from_me) {
                $waits[$m->chat_id] ??= $ts;
                continue;
            }
            $who = $m->sent_by ?: (int) ($assigned[$m->chat_id] ?? 0);
            if ($m->source !== 'ai' && $m->source !== 'campaign') {
                $key = isset($rows[$who]) ? $who : self::UNASSIGNED;
                $rows[$key]['messages']++;
            }
            if (!isset($waits[$m->chat_id])) {
                continue;
            }
            $delta = max(0, $ts - $waits[$m->chat_id]);
            unset($waits[$m->chat_id]);
            if ($m->source === 'ai') {
                $aiAnswered++;
            } elseif ($m->source !== 'campaign') {
                $key = isset($rows[$who]) ? $who : self::UNASSIGNED;
                $rows[$key]['answered']++;
                $times[$key][] = $delta;
            }
        }

        foreach ($times as $who => $list) {
            sort($list);
            $rows[$who]['avg_response'] = (int) round(array_sum($list) / count($list));
            $rows[$who]['median_response'] = (int) $list[intdiv(count($list), 2)];
        }

        $this->deals($owner, $from, $to, $rows);
        $this->tasks($owner, $from, $to, $rows);

        $people = array_values(array_filter($rows, fn ($r) => $r['id'] !== self::UNASSIGNED || $r['answered'] || $r['messages'] || $r['won'] || $r['lost'] || $r['tasks_done']));
        $all = array_merge(...array_values($times) ?: [[]]);
        sort($all);

        return [
            'people' => $people, 'from' => $from, 'to' => $to, 'ai_answered' => $aiAnswered,
            'totals' => [
                'answered' => array_sum(array_column($people, 'answered')), 'messages' => array_sum(array_column($people, 'messages')),
                'won' => array_sum(array_column($people, 'won')), 'won_value' => array_sum(array_column($people, 'won_value')),
                'lost' => array_sum(array_column($people, 'lost')), 'tasks_done' => array_sum(array_column($people, 'tasks_done')),
                'avg_response' => $all ? (int) round(array_sum($all) / count($all)) : null, 'median_response' => $all ? (int) $all[intdiv(count($all), 2)] : null,
            ],
        ];
    }

    private function blank(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'answered' => 0, 'messages' => 0, 'avg_response' => null, 'median_response' => null,
            'won' => 0, 'won_value' => 0.0, 'lost' => 0, 'tasks_done' => 0, 'tasks_overdue' => 0];
    }

    private function deals(User $owner, Carbon $from, Carbon $to, array &$rows): void
    {
        $d = Deal::where('user_id', $owner->id)->whereIn('status', ['won', 'lost'])->whereBetween('closed_at', [$from, $to])
            ->selectRaw('coalesce(assigned_to, 0) as who, status, count(*) as n, coalesce(sum(value), 0) as v')->groupBy('who', 'status')->get();
        foreach ($d as $r) {
            $k = isset($rows[(int) $r->who]) ? (int) $r->who : self::UNASSIGNED;
            if ($r->status === 'won') {
                $rows[$k]['won'] += (int) $r->n;
                $rows[$k]['won_value'] += (float) $r->v;
            } else {
                $rows[$k]['lost'] += (int) $r->n;
            }
        }
    }

    private function tasks(User $owner, Carbon $from, Carbon $to, array &$rows): void
    {
        foreach (Task::where('user_id', $owner->id)->whereNotNull('done_at')->whereBetween('done_at', [$from, $to])
            ->selectRaw('coalesce(done_by, assigned_to, 0) as who, count(*) as n')->groupBy('who')->get() as $r) {
            $rows[isset($rows[(int) $r->who]) ? (int) $r->who : self::UNASSIGNED]['tasks_done'] += (int) $r->n;
        }
        // overdue right now, whatever the period
        foreach (Task::where('user_id', $owner->id)->whereNull('done_at')->whereNotNull('due_at')->where('due_at', '<', now())
            ->selectRaw('coalesce(assigned_to, 0) as who, count(*) as n')->groupBy('who')->get() as $r) {
            $rows[isset($rows[(int) $r->who]) ? (int) $r->who : self::UNASSIGNED]['tasks_overdue'] += (int) $r->n;
        }
    }

    /**
     * Progress of the goals in the month of $at: one entry per goal with the current value.
     *
     * @return array<int,array{id:int,member_id:int,member:string,metric:string,target:float,value:float,pct:?int,reached:bool}>
     */
    public function goals(User $owner, ?Carbon $at = null): array
    {
        $goals = TeamGoal::where('user_id', $owner->id)->orderBy('member_id')->orderBy('metric')->get();
        if ($goals->isEmpty()) {
            return [];
        }
        $at ??= now();
        $report = $this->build($owner, $at->copy()->startOfMonth(), $at->copy()->endOfMonth());
        $by = collect($report['people'])->keyBy('id');
        $names = $this->team->people($owner)->pluck('name', 'id');

        return $goals->map(function (TeamGoal $g) use ($report, $by, $names) {
            $src = $g->member_id ? ($by[$g->member_id] ?? $this->blank($g->member_id, '')) : $report['totals'] + ['median_response' => $report['totals']['median_response']];
            $value = match ($g->metric) {
                'deals_won' => (float) $src['won'],
                'won_value' => (float) $src['won_value'],
                'answered' => (float) $src['answered'],
                default => $src['avg_response'] === null ? 0.0 : round($src['avg_response'] / 60, 1), // minutes
            };
            $lowerIsBetter = $g->metric === 'first_response_minutes';
            $reached = $lowerIsBetter ? ($src['avg_response'] !== null && $value <= $g->target) : $value >= $g->target;
            $pct = $lowerIsBetter ? null : ($g->target > 0 ? (int) min(100, round($value / $g->target * 100)) : null);

            return ['id' => $g->id, 'member_id' => (int) $g->member_id, 'member' => $g->member_id ? ($names[$g->member_id] ?? '—') : __('Whole team'),
                'metric' => $g->metric, 'target' => $g->target, 'value' => $value, 'pct' => $pct, 'reached' => $reached];
        })->all();
    }

    /** "2 h 5 min" / "45 s" / "—" */
    public static function human(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        $m = intdiv($seconds, 60);

        return $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h ' . ($m % 60 ? ($m % 60) . ' min' : '');
    }
}
