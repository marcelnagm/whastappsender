<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app notice: a colleague mentioned you (@name) in an internal note. */
class MentionNotice extends Notification
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
        return ['title' => __('Mention'), 'message' => $this->message, 'type' => 'info', 'url' => $this->url ?: route('chat.index')];
    }
}
