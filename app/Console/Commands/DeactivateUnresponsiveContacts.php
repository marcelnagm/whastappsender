<?php

namespace App\Console\Commands;

use App\Services\Contacts\UnresponsiveContacts;
use Illuminate\Console\Command;

class DeactivateUnresponsiveContacts extends Command
{
    protected $signature = 'contacts:deactivate-unresponsive {--dry-run : only list what would be deactivated} {--hours= : override CONTACT_UNDELIVERED_AFTER_HOURS}';
    protected $description = 'Deactivate WhatsApp contacts whose messages never get delivered';

    public function handle(UnresponsiveContacts $service): int
    {
        $hours = $this->option('hours') !== null ? (int) $this->option('hours') : null;
        $r = $service->run($hours, (bool) $this->option('dry-run'));

        $verb = $this->option('dry-run') ? 'would be deactivated' : 'deactivated';
        $this->info(count($r['deactivated']) . " contact(s) {$verb} ({$r['checked']} with undelivered messages checked).");
        if ($r['deactivated']) {
            $this->line('IDs: ' . implode(', ', $r['deactivated']));
        }
        if ($r['skipped_accounts']) {
            $this->warn('Skipped accounts without delivery receipts (check the Evolution MESSAGES_UPDATE webhook): ' . implode(', ', $r['skipped_accounts']));
        }

        return self::SUCCESS;
    }
}
