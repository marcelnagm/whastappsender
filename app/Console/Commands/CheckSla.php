<?php

namespace App\Console\Commands;

use App\Services\Sla\SlaMonitor;
use Illuminate\Console\Command;

class CheckSla extends Command
{
    protected $signature = 'sla:check';
    protected $description = 'Alert and escalate customers waiting too long for a first answer';

    public function handle(SlaMonitor $monitor): int
    {
        $r = $monitor->run();
        $this->info("{$r['breaches']} breach(es), {$r['escalations']} escalation(s).");

        return self::SUCCESS;
    }
}
