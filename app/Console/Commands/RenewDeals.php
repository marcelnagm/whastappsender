<?php

namespace App\Console\Commands;

use App\Services\Pipeline\Renewals;
use Illuminate\Console\Command;

class RenewDeals extends Command
{
    protected $signature = 'deals:renewals';
    protected $description = 'Create renewal tasks for subscriptions renewing soon';

    public function handle(Renewals $svc): int
    {
        $this->info($svc->run() . ' renewal(s) reported.');

        return self::SUCCESS;
    }
}
