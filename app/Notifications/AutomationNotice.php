<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app notice sent by an automation's "notify" action. */
class AutomationNotice extends Notification
{
    public function __construct(public string $message, public ?string $url = null)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return ['title' => __('Automation'), 'message' => $this->message, 'type' => 'info', 'url' => $this->url ?: route('pipeline.index')];
    }
}
