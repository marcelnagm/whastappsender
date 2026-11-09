<?php

namespace App\Services\Insights;

use App\Models\InsightSchedule;
use App\Models\User;
use App\Notifications\InsightReady;
use Illuminate\Support\Carbon;

/** Generates and sends the automatic reports that are due today. One failing account never stops the others. */
class InsightScheduler
{
    public function __construct(private InsightGenerator $gen)
    {
    }

    /** @return int reports sent */
    public function run(?Carbon $now = null): int
    {
        $sent = 0;
        InsightSchedule::where('enabled', true)->get()->each(function (InsightSchedule $s) use ($now, &$sent) {
            if (!$s->isDue($now)) {
                return;
            }
            $owner = User::find($s->user_id);
            if (!$owner) {
                return;
            }
            try {
                $report = $this->gen->generate($owner, $s->business_type, (int) $s->period_days);
                $s->update(['last_run_at' => $now ?: now(), 'last_error' => null]);
                try {
                    $owner->notify(new InsightReady($report));
                } catch (\Throwable $e) {
                    // the report exists and is in the app even if the e-mail server is down
                    \Log::warning('Insight e-mail failed: ' . $e->getMessage());
                }
                $this->whatsapp($owner, $s, $report);
                $sent++;
            } catch (\Throwable $e) {
                // out of plan, no agent, provider down: tried today, the reason is shown on the Insights page
                $s->update(['last_run_at' => $now ?: now(), 'last_error' => mb_substr($e->getMessage(), 0, 290)]);
            }
        });

        return $sent;
    }

    /** Short version of the report on WhatsApp, sent from the account's first connected number. Failures only get logged. */
    private function whatsapp(User $owner, InsightSchedule $s, \App\Models\InsightReport $report): void
    {
        if (!$s->whatsapp_number) {
            return;
        }
        try {
            $instance = $owner->instances()->where('status', 'connected')->orderBy('id')->first();
            if (!$instance) {
                throw new \RuntimeException('no connected WhatsApp number');
            }
            $lines = ['*' . __('Your business report is ready') . '*'];
            if ($report->summary) {
                $lines[] = $report->summary;
            }
            foreach (collect($report->blocks)->where('type', 'kpi')->take(5) as $k) {
                $lines[] = '• ' . $k['label'] . ': ' . $k['value'] . ($k['hint'] ? ' (' . $k['hint'] . ')' : '');
            }
            $recs = collect($report->blocks)->where('type', 'recommendation')->take(3);
            if ($recs->isNotEmpty()) {
                $lines[] = '*' . __('Recommended actions') . '*';
                foreach ($recs as $r) {
                    $lines[] = '→ ' . $r['title'];
                }
            }
            $lines[] = route('ai.insights.index', ['r' => $report->id]);
            app(\App\Services\Chat\EvolutionChatClient::class)->sendText($instance->instance_name, $s->whatsapp_number, implode("\n", $lines));
        } catch (\Throwable $e) {
            \Log::warning('Insight WhatsApp failed: ' . $e->getMessage());
        }
    }
}
