<?php

namespace App\Console\Commands;

use App\Services\Chat\Snooze;
use Illuminate\Console\Command;

class WakeSnoozedChats extends Command
{
    protected $signature = 'chat:wake';
    protected $description = 'Bring snoozed conversations back to the inbox when their time comes';

    public function handle(Snooze $svc): int
    {
        $this->info($svc->run() . ' conversation(s) woke up.');

        return self::SUCCESS;
    }
}
