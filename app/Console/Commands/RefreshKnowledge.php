<?php

namespace App\Console\Commands;

use App\Jobs\CrawlKnowledgeSiteJob;
use App\Models\KnowledgeSource;
use Illuminate\Console\Command;

class RefreshKnowledge extends Command
{
    protected $signature = 'knowledge:refresh';
    protected $description = 'Read the websites taught to the AI again';

    public function handle(): int
    {
        $n = 0;
        KnowledgeSource::where('type', 'site')->orderBy('id')->each(function (KnowledgeSource $s) use (&$n) {
            $s->forceFill(['status' => 'pending'])->save();
            CrawlKnowledgeSiteJob::dispatch($s->id);
            $n++;
        });
        $this->info($n . ' website(s) queued.');

        return self::SUCCESS;
    }
}
