<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class PruneAuditLog extends Command
{
    protected $signature = 'audit:prune {--days=365}';
    protected $description = 'Delete audit entries older than N days';

    public function handle(): int
    {
        $n = AuditLog::where('created_at', '<', now()->subDays(max(30, (int) $this->option('days'))))->delete();
        $this->info("{$n} audit entries removed.");

        return self::SUCCESS;
    }
}
