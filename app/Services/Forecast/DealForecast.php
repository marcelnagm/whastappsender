<?php

namespace App\Services\Forecast;

use App\Models\AiProfile;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\AiProfileResolver;
use App\Services\AI\Llm\LlmClient;
use App\Services\AI\Llm\LlmException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chance to close and priority of open deals. The score is a transparent heuristic built from signals the CRM already has
 * (stage reached, how recently the customer wrote, whether the customer is waiting for an answer, follow-ups, age, expected
 * date, contact score): every point comes with its reason, so a person can disagree with it. The AI agent is only asked
 * for a written recommendation on request, never to invent the number.
 */
class DealForecast
{
    public function __construct(private LlmClient $llm, private AiProfileResolver $profiles)
    {
    }

    /**
     * @param Collection<int,Deal> $deals
     * @return array<int,array{score:?int,level:string,reasons:array<int,array{delta:int,text:string}>,next:string,waiting_hours:?int}> by deal id
     */
    public function scoreMany(Collection $deals): array
    {
        if ($deals->isEmpty()) {
            return [];
        }
        if (method_exists($deals, 'loadMissing')) {
            $deals->loadMissing(['contact:id,score']);
        }
        $chatIds = $deals->pluck('chat_id')->filter()->unique()->all();
        $now = now();

        // last customer message / last reply / customer messages in the last 14 days, per chat, in 3 queries
        $lastIn = $lastOut = $recentIn = [];
        if ($chatIds) {
            foreach (ChatMessage::whereIn('chat_id', $chatIds)->where('deleted', false)->where('from_me', false)->groupBy('chat_id')->selectRaw('chat_id, max(coalesce(message_timestamp, created_at)) as t')->get() as $r) {
                $lastIn[$r->chat_id] = \Illuminate\Support\Carbon::parse($r->t);
            }
            foreach (ChatMessage::whereIn('chat_id', $chatIds)->where('deleted', false)->where('from_me', true)->groupBy('chat_id')->selectRaw('chat_id, max(coalesce(message_timestamp, created_at)) as t')->get() as $r) {
                $lastOut[$r->chat_id] = \Illuminate\Support\Carbon::parse($r->t);
            }
            foreach (ChatMessage::whereIn('chat_id', $chatIds)->where('deleted', false)->where('from_me', false)->where(fn ($w) => $w->where('message_timestamp', '>=', $now->copy()->subDays(14))->orWhere(fn ($x) => $x->whereNull('message_timestamp')->where('created_at', '>=', $now->copy()->subDays(14))))
                ->groupBy('chat_id')->selectRaw('chat_id, count(*) as n')->get() as $r) {
                $recentIn[$r->chat_id] = (int) $r->n;
            }
        }

        $tasks = Task::whereIn('deal_id', $deals->pluck('id'))->whereNull('done_at')->get()->groupBy('deal_id');

        // position of each stage among the open stages of its pipeline (0 = first)
        $stageIdx = [];
        foreach (PipelineStage::whereIn('pipeline_id', $deals->pluck('pipeline_id')->unique())->where('kind', 'open')->orderBy('position')->orderBy('id')->get()->groupBy('pipeline_id') as $stages) {
            foreach ($stages->values() as $i => $s) {
                $stageIdx[$s->id] = [$i, $stages->count()];
            }
        }

        $out = [];
        foreach ($deals as $d) {
            if ($d->status !== 'open') {
                $out[$d->id] = ['score' => null, 'level' => $d->status, 'reasons' => [], 'next' => '', 'waiting_hours' => null];
                continue;
            }
            $reasons = [];
            $add = function (int $delta, string $text) use (&$reasons) {
                $reasons[] = ['delta' => $delta, 'text' => $text];
            };

            [$i, $n] = $stageIdx[$d->stage_id] ?? [0, 1];
            $score = 10 + (int) round(50 * $i / max(1, $n - 1));
            $add($score, __('Stage reached'));

            $in = $d->chat_id ? ($lastIn[$d->chat_id] ?? null) : null;
            $out_ = $d->chat_id ? ($lastOut[$d->chat_id] ?? null) : null;
            $waiting = null;
            if ($d->chat_id) {
                if (!$in) {
                    $add(-10, __('The customer has not written'));
                } else {
                    $days = $in->diffInHours($now) / 24;
                    [$delta, $why] = match (true) {
                        $days <= 1 => [20, __('The customer wrote in the last day')],
                        $days <= 3 => [12, __('The customer wrote in the last 3 days')],
                        $days <= 7 => [5, __('The customer wrote in the last week')],
                        $days <= 14 => [0, ''],
                        default => [-10, __('The customer has been silent for over 2 weeks')],
                    };
                    if ($why !== '') {
                        $add($delta, $why);
                    }
                    if (!$out_ || $out_->lt($in)) {
                        $waiting = (int) $in->diffInHours($now);
                    }
                }
                if (($recentIn[$d->chat_id] ?? 0) >= 5) {
                    $add(5, __('Active conversation'));
                }
            }

            $age = $d->created_at ? $d->created_at->diffInDays($now) : 0;
            if ($age > 60) {
                $add(-10, __('Open for over 60 days'));
            } elseif ($age > 30) {
                $add(-5, __('Open for over 30 days'));
            }

            $open = $tasks[$d->id] ?? collect();
            $overdue = $open->filter(fn ($t) => $t->due_at && $t->due_at->lt($now))->count();
            if ($overdue) {
                $add(-5, __('Overdue follow-up'));
            } elseif ($open->isNotEmpty()) {
                $add(3, __('Follow-up scheduled'));
            }

            if ($d->expected_close_date) {
                if ($d->expected_close_date->lt($now->copy()->startOfDay())) {
                    $add(-8, __('Expected close date has passed'));
                } elseif ($d->expected_close_date->lte($now->copy()->addDays(7))) {
                    $add(5, __('Expected to close within a week'));
                }
            }

            $cs = $d->contact?->score;
            if ($cs !== null) {
                if ($cs >= 70) {
                    $add(8, __('High contact score'));
                } elseif ($cs <= 20) {
                    $add(-5, __('Low contact score'));
                }
            }

            $total = max(1, min(99, array_sum(array_column($reasons, 'delta'))));
            $next = match (true) {
                $waiting !== null && $waiting >= 1 => __('Reply to the customer, who has been waiting :h h', ['h' => $waiting]),
                $overdue > 0 => __('Do the overdue follow-up'),
                $open->isEmpty() && (!$in || $in->diffInDays($now) >= 3) => __('Schedule a follow-up'),
                !$d->expected_close_date => __('Set an expected close date'),
                default => __('Keep the deal moving'),
            };

            $out[$d->id] = ['score' => $total, 'level' => $total >= 66 ? 'hot' : ($total >= 36 ? 'warm' : 'cold'), 'reasons' => $reasons, 'next' => $next, 'waiting_hours' => $waiting];
        }

        return $out;
    }

    /** Value weighted by chance to close, over open deals. */
    public function weightedValue(Collection $deals, array $scores): float
    {
        return round((float) $deals->where('status', 'open')->sum(fn ($d) => (float) $d->value * (($scores[$d->id]['score'] ?? 0) / 100)), 2);
    }

    /**
     * A short written recommendation from the account's AI agent. Fails soft: returns null when no agent is available.
     *
     * @return array{text:?string,error:?string}
     */
    public function advice(Deal $deal, User $owner, array $forecast): array
    {
        $chat = $deal->chat_id ? Chat::where('user_id', $owner->id)->with('instance')->find($deal->chat_id) : null;
        $profile = $chat && $chat->instance ? $this->profiles->forInstance($owner, $chat->instance) : null;
        $profile ??= AiProfile::where('user_id', $owner->id)->where('enabled', true)->orderBy('id')->first();
        if (!$profile) {
            return ['text' => null, 'error' => __('Enable an AI agent to get recommendations.')];
        }

        $lines = [];
        if ($chat) {
            $msgs = ChatMessage::where('chat_id', $chat->id)->where('deleted', false)->whereNotNull('body')->orderByDesc('id')->limit(15)->get()->reverse();
            foreach ($msgs as $m) {
                $lines[] = ($m->from_me ? 'ATTENDANT' : 'CUSTOMER') . ': ' . mb_substr((string) $m->body, 0, 300);
            }
        }
        $facts = collect($forecast['reasons'])->map(fn ($r) => ($r['delta'] >= 0 ? '+' : '') . $r['delta'] . ' ' . $r['text'])->implode('; ');
        $prompt = "Deal: {$deal->title}. Value: {$deal->value}. Chance to close (heuristic): {$forecast['score']}%. Signals: {$facts}.\n"
            . ($lines ? "Recent conversation:\n" . implode("\n", $lines) : 'No conversation linked.');

        try {
            $r = $this->llm->chat($profile, 'You are a sales coach for a small business. In at most 3 short sentences, in the language of the conversation (Portuguese if unclear), say what the attendant should do next to close this deal and what the main risk is. Do not invent facts; use only what is given. Do not promise discounts.', [['role' => 'user', 'content' => $prompt]]);

            return ['text' => trim($r['reply']) ?: null, 'error' => null];
        } catch (LlmException $e) {
            return ['text' => null, 'error' => __('The AI agent could not answer now.')];
        }
    }
}
