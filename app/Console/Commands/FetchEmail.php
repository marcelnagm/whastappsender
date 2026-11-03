<?php

namespace App\Console\Commands;

use App\Jobs\FetchEmailJob;
use App\Models\Instance;
use Illuminate\Console\Command;

class FetchEmail extends Command
{
    protected $signature = 'email:fetch {instance? : instance id (default: every mailbox with IMAP)} {--now : run here instead of queueing}';

    protected $description = 'Import new e-mail from the connected mailboxes (IMAP) into the chat';

    public function handle(): int
    {
        $q = Instance::where('channel', 'email')->whereNotNull('imap_host');
        if ($id = $this->argument('instance')) {
            $q->whereKey($id);
        }

        $n = 0;
        foreach ($q->cursor() as $i) {
            $this->option('now') ? FetchEmailJob::dispatchSync($i->id) : FetchEmailJob::dispatch($i->id)->onQueue('default');
            $n++;
        }
        $this->info("{$n} mailbox(es) " . ($this->option('now') ? 'checked' : 'queued') . '.');

        return self::SUCCESS;
    }
}
