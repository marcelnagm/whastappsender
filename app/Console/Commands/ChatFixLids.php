<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Services\Chat\LidResolver;
use Illuminate\Console\Command;

class ChatFixLids extends Command
{
    protected $signature = 'chat:fix-lids {--no-http : only use data already in the database (contacts), do not ask Evolution}';

    protected $description = 'Convert chats identified by an opaque @lid into the real phone number (merging duplicates)';

    public function handle(LidResolver $resolver): int
    {
        $fixed = $left = 0;

        Chat::where('remote_jid', 'like', '%@lid')->orderBy('id')->each(function (Chat $chat) use ($resolver, &$fixed, &$left) {
            $result = $resolver->fixChat($chat, !$this->option('no-http'));
            $result ? $fixed++ : $left++;
        });

        $this->info("Converted {$fixed} chat(s); {$left} still without a known number.");

        return self::SUCCESS;
    }
}
