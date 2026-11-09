<?php

namespace App\Console\Commands;

use App\Services\Insights\InsightScheduler;
use Illuminate\Console\Command;

class SendInsights extends Command
{
    protected $signature = 'insights:send';
    protected $description = 'Generate and send the scheduled AI business reports that are due today';

    public function handle(InsightScheduler $svc): int
    {
        $this->info($svc->run() . ' report(s) sent.');

        return self::SUCCESS;
    }
}
