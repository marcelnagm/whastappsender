<?php

namespace App\Console\Commands;

use App\Services\Tasks\FollowUps;
use Illuminate\Console\Command;

class RemindTasks extends Command
{
    protected $signature = 'tasks:remind';
    protected $description = 'Notify the person responsible for every follow-up task that has fallen due';

    public function handle(FollowUps $followUps): int
    {
        $this->info($followUps->remindDue() . ' reminder(s) sent.');

        return self::SUCCESS;
    }
}
