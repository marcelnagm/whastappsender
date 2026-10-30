<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Services\Telegram\TelegramInbound;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $instanceId, public array $update)
    {
    }

    public function handle(TelegramInbound $inbound): void
    {
        $instance = Instance::find($this->instanceId);
        if ($instance && $instance->isTelegram()) {
            $inbound->handle($instance, $this->update);
        }
    }
}
