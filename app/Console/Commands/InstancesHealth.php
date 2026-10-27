<?php

namespace App\Console\Commands;

use App\Services\Instances\InstanceHealth;
use Illuminate\Console\Command;

class InstancesHealth extends Command
{
    protected $signature = 'instances:health';

    protected $description = 'Check busy WhatsApp sessions against Evolution, alert owners when sending stopped and admins when Evolution is down';

    public function handle(InstanceHealth $health): int
    {
        $r = $health->checkAll();

        if (!$r['evolution']) {
            $this->error('Evolution API is not answering (admins were notified once).');

            return self::FAILURE;
        }

        $this->info("Checked {$r['checked']} busy session(s); {$r['down']} down; {$r['confirmed']} outage(s) confirmed.");

        return self::SUCCESS;
    }
}
