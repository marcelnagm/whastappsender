<?php

namespace App\Console\Commands;

use App\Services\Chat\Sequences;
use Illuminate\Console\Command;

class RunSequences extends Command
{
    protected $signature = 'sequences:run';
    protected $description = 'Send the follow-up sequence steps that are due';

    public function handle(Sequences $svc): int
    {
        $this->info($svc->dispatchDue() . ' sequence message(s) sent.');

        return self::SUCCESS;
    }
}
