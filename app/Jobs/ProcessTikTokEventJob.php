<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Services\TikTok\TikTokInbound;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTikTokEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public array $body)
    {
    }

    public function handle(TikTokInbound $inbound): void
    {
        $content = $this->body['content'] ?? [];
        if (is_string($content)) {
            $content = json_decode($content, true) ?: [];
        }
        if (($this->body['event'] ?? 'im_send_msg') !== 'im_send_msg' || !is_array($content)) {
            return; // read receipts and other events are not stored
        }

        $business = $this->body['business_id'] ?? $this->body['user_openid'] ?? $content['business_id'] ?? null;
        $instance = $business ? Instance::where('channel', 'tiktok')->where('tiktok_open_id', (string) $business)->first() : null;
        if (!$instance) {
            return;
        }

        try {
            $inbound->handle($instance, $content);
        } catch (\Throwable $e) {
            Log::warning('TikTok event failed: ' . $e->getMessage(), ['instance_id' => $instance->id]);
        }
    }
}
