<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Notifications\Notification;

/** In-app reminder: a follow-up task reached its due time. */
class TaskDue extends Notification
{
    public function __construct(public Task $task)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $who = optional($this->task->contact)->name;

        return [
            'title' => __('Follow-up due'),
            'message' => $this->task->title . ($who ? ' · ' . $who : ''),
            'type' => 'alert',
            'url' => route('tasks.index'),
        ];
    }
}
