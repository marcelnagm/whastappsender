<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Services\Facebook\FacebookInbound;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Handles one webhook delivery: entry.id is the Page, entry.messaging[] the events. */
class ProcessFacebookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public array $payload)
    {
    }

    public function handle(FacebookInbound $inbound): void
    {
        foreach ((array) ($this->payload['entry'] ?? []) as $entry) {
            $instance = Instance::where('channel', 'facebook')->where('facebook_page_id', (string) ($entry['id'] ?? ''))->first();
            if (!$instance) {
                continue;
            }
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                try {
                    $inbound->handle($instance, (array) $event);
                } catch (\Throwable $e) {
                    Log::warning('Facebook event failed: ' . $e->getMessage(), ['instance_id' => $instance->id]);
                }
            }
        }
    }
}
