<?php

namespace App\Console\Commands;

use App\Services\Contacts\Birthdays;
use Illuminate\Console\Command;

class ContactBirthdays extends Command
{
    protected $signature = 'contacts:birthdays';
    protected $description = 'Fire the "birthday" automation for people celebrating today';

    public function handle(Birthdays $svc): int
    {
        $this->info($svc->run() . ' birthday(s) fired.');

        return self::SUCCESS;
    }
}
