<?php

namespace App\Jobs;

use App\Models\OutboundWebhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\OutboundWebhooks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public int $deliveryId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(): void
    {
        $d = WebhookDelivery::find($this->deliveryId);
        $hook = $d ? OutboundWebhook::find($d->webhook_id) : null;
        if (!$d || !$hook || $d->ok) {
            return;
        }
        $d->increment('attempts');
        if (!OutboundWebhooks::urlAllowed($hook->url)) {
            $d->update(['ok' => false, 'error' => 'URL not allowed']);

            return;
        }
        try {
            $res = Http::timeout(10)->withoutRedirecting()->withHeaders([
                'Content-Type' => 'application/json', 'User-Agent' => 'SAMA-Webhook/1.0', 'X-Sama-Event' => $d->event, 'X-Sama-Delivery' => $d->uuid,
                'X-Sama-Signature' => OutboundWebhooks::sign($d->payload, $hook->secret),
            ])->withBody($d->payload, 'application/json')->post($hook->url);
            $ok = $res->successful();
            $d->update(['status_code' => $res->status(), 'ok' => $ok, 'error' => $ok ? null : 'HTTP ' . $res->status()]);
        } catch (\Throwable $e) {
            $ok = false;
            $d->update(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        if ($ok) {
            $hook->forceFill(['failures' => 0, 'last_delivery_at' => now()])->save();

            return;
        }
        // count a failure only once the retries are used up, then switch the endpoint off after many
        if ($this->attempts() >= $this->tries) {
            $hook->forceFill(['failures' => $hook->failures + 1, 'last_delivery_at' => now(), 'active' => $hook->failures + 1 < OutboundWebhooks::DISABLE_AFTER])->save();

            return;
        }
        $this->release($this->backoff()[$this->attempts() - 1] ?? 1800);
    }
}
