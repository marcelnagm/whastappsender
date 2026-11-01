<?php

namespace App\Console\Commands;

use App\Services\Contacts\ContactScorer;
use Illuminate\Console\Command;

class ScoreContacts extends Command
{
    protected $signature = 'contacts:score {--user= : only this user id} {--all : recalculate everything, not only what is due} {--limit=2000}';
    protected $description = 'Recalculate the automatic engagement score of contacts';

    public function handle(ContactScorer $scorer): int
    {
        $n = $scorer->refreshDue($this->option('user') ? (int) $this->option('user') : null, (int) $this->option('limit'), (bool) $this->option('all'));
        $this->info("{$n} contact(s) scored.");

        return self::SUCCESS;
    }
}
