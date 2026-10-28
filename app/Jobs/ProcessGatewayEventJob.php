<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Payments\GatewayWebhookHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Applies one stored Asaas / Efí / AbacatePay notification. Retries with growing delays while the provider API is unreachable. */
class ProcessGatewayEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $webhookEventId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(GatewayWebhookHandler $handler): void
    {
        $event = WebhookEvent::find($this->webhookEventId);
        if (!$event || in_array($event->status, ['processed', 'ignored'], true)) {
            return;
        }

        try {
            [$status, $note] = $handler->handle((string) $event->provider, (string) $event->type, (string) $event->resource_id);
        } catch (\Throwable $e) {
            $event->update(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 250)]);
            throw $e;
        }

        $event->update(['status' => $status === GatewayWebhookHandler::RETRY ? 'received' : $status, 'note' => mb_substr($note, 0, 250)]);

        if ($status === GatewayWebhookHandler::RETRY) {
            throw new \RuntimeException($note);
        }
    }

    public function failed(\Throwable $e): void
    {
        WebhookEvent::where('id', $this->webhookEventId)->update(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 250)]);
    }
}
