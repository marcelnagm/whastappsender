<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app notification when the chat history import of a number finishes (or fails). */
class ChatHistorySynced extends Notification
{
    public function __construct(public string $instanceName, public int $chats, public int $messages, public ?string $error = null)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        if ($this->error) {
            return [
                'title' => __('Chat history import failed'),
                'message' => __(':name: :error', ['name' => $this->instanceName, 'error' => $this->error]),
                'type' => 'alert',
            ];
        }

        return [
            'title' => __('Chat history imported'),
            'message' => __(':name: :chats conversation(s), :messages message(s).', ['name' => $this->instanceName, 'chats' => $this->chats, 'messages' => $this->messages]),
            'type' => 'info',
        ];
    }
}
