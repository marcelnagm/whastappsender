<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Services\Email\EmailPoller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Imports the new mail of one mailbox (and lets the AI answer it). */
class FetchEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $instanceId)
    {
    }

    public function handle(EmailPoller $poller): void
    {
        $i = Instance::find($this->instanceId);
        if ($i && $i->isEmail()) {
            $poller->poll($i);
        }
    }
}
