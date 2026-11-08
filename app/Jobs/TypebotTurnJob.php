<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Bots\TypebotBot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One customer message through the Typebot flow (off the webhook, so a slow bot never delays the chat). */
class TypebotTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $chatId, public int $messageId)
    {
    }

    public function handle(TypebotBot $bot): void
    {
        $chat = Chat::with('contact')->find($this->chatId);
        $m = ChatMessage::find($this->messageId);
        if ($chat && $m) {
            $bot->turn($chat, $m);
        }
    }
}
