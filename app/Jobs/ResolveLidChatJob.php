<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Services\Chat\LidResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Background conversion of a "@lid" chat into the real phone number. */
class ResolveLidChatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $chatId)
    {
    }

    public function handle(LidResolver $resolver): void
    {
        if ($chat = Chat::find($this->chatId)) {
            $resolver->fixChat($chat);
        }
    }
}
