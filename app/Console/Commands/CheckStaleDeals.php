<?php

namespace App\Console\Commands;

use App\Services\Pipeline\StaleDeals;
use Illuminate\Console\Command;

class CheckStaleDeals extends Command
{
    protected $signature = 'deals:stale';
    protected $description = 'Warn about open deals without activity';

    public function handle(StaleDeals $svc): int
    {
        $this->info($svc->run() . ' stalled deal(s) reported.');

        return self::SUCCESS;
    }
}
