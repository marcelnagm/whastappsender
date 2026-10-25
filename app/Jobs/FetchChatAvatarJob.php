<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Services\Chat\ChatAvatarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Downloads and stores one contact's WhatsApp profile picture (see ChatAvatarService).
 * A failure is not retried here: the chat is marked to be tried again in a few minutes.
 */
class FetchChatAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public int $chatId)
    {
    }

    public function handle(ChatAvatarService $avatars): void
    {
        $chat = Chat::with('instance')->find($this->chatId);

        if ($chat) {
            $avatars->refresh($chat);
        }
    }
}
