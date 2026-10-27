<?php

namespace App\Jobs;

use App\Services\Instances\InstanceHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Delayed check (debounce) after a session reported a disconnect. See InstanceHealth::confirmDown(). */
class ConfirmInstanceDownJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public int $instanceId)
    {
    }

    public function handle(InstanceHealth $health): void
    {
        $health->confirmDown($this->instanceId);
    }
}
