<?php

namespace App\Console\Commands;

use App\Services\Chat\ScheduledMessages;
use Illuminate\Console\Command;

class SendScheduledMessages extends Command
{
    protected $signature = 'chat:send-scheduled';
    protected $description = 'Send the chat messages scheduled for now';

    public function handle(ScheduledMessages $svc): int
    {
        $this->info($svc->dispatchDue() . ' scheduled message(s) sent.');

        return self::SUCCESS;
    }
}
