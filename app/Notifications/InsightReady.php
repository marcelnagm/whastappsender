<?php

namespace App\Notifications;

use App\Models\InsightReport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The scheduled AI report is ready: in-app notice plus an e-mail with the summary and the top actions. */
class InsightReady extends Notification
{
    public function __construct(public InsightReport $report)
    {
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray($notifiable): array
    {
        return ['title' => __('Your business report is ready'), 'message' => $this->report->summary ?: __('Open the report to see the findings.'),
            'type' => 'info', 'url' => route('ai.insights.index', ['r' => $this->report->id])];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__('Your business report is ready'))->greeting(__('Hello, :name', ['name' => $notifiable->name]));
        if ($this->report->summary) {
            $mail->line($this->report->summary);
        }
        foreach (collect($this->report->blocks)->where('type', 'kpi')->take(5) as $k) {
            $mail->line('• ' . $k['label'] . ': ' . $k['value'] . ($k['hint'] ? ' (' . $k['hint'] . ')' : ''));
        }
        $recs = collect($this->report->blocks)->where('type', 'recommendation')->take(3);
        if ($recs->isNotEmpty()) {
            $mail->line(__('Recommended actions') . ':');
            foreach ($recs as $r) {
                $mail->line('→ ' . $r['title']);
            }
        }

        return $mail->action(__('Open the full report'), route('ai.insights.index', ['r' => $this->report->id]));
    }
}
