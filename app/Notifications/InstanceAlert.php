<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operational alerts. $type: instance_down | instance_recovered | evolution_unreachable | evolution_recovered | queue_backlog
 * Delivered in the app (bell) and by e-mail.
 */
class InstanceAlert extends Notification
{
    public function __construct(public string $type, public array $data = [])
    {
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    private function name(): string
    {
        return (string) ($this->data['instance'] ?? '');
    }

    private function subject(): string
    {
        return match ($this->type) {
            'instance_down' => __('Sending stopped: WhatsApp :name is disconnected', ['name' => $this->name()]),
            'instance_recovered' => __('WhatsApp :name is connected again', ['name' => $this->name()]),
            'evolution_unreachable' => __('The WhatsApp gateway (Evolution API) is not answering'),
            'evolution_recovered' => __('The WhatsApp gateway (Evolution API) is back'),
            'queue_backlog' => __('Queue backlog: :queue has :size waiting jobs', ['queue' => $this->data['queue'] ?? '', 'size' => $this->data['size'] ?? '']),
            default => __('Alert'),
        };
    }

    private function body(): string
    {
        return match ($this->type) {
            'instance_down' => __(':pending message(s) are waiting and cannot be sent from this number. Reconnect it (scan the QR code again) to resume sending.', ['pending' => (int) ($this->data['pending'] ?? 0)])
                . (!empty($this->data['since']) ? ' ' . __('Disconnected since :since.', ['since' => $this->data['since']]) : ''),
            'instance_recovered' => __('Sending resumes with the next batch.'),
            'evolution_unreachable' => __('Sending is paused for every number until it answers again. Check the Evolution API container / service.'),
            'evolution_recovered' => __('The gateway answers again.'),
            'queue_backlog' => __('Workers are not keeping up or are stopped. Check the queue workers.'),
            default => '',
        };
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject())
            ->greeting(__('Hello, :name', ['name' => $notifiable->name]))
            ->line($this->body());

        if (!empty($this->data['url'])) {
            $mail->action(__('Open'), $this->data['url']);
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return ['title' => $this->subject(), 'message' => $this->body(), 'type' => 'alert', 'alert_type' => $this->type] + $this->data;
    }
}
