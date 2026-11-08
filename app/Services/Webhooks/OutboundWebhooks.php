<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\OutboundWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Sends what happens in the CRM to the customer's own URLs (Zapier, n8n, Make...), signed with HMAC-SHA256. */
class OutboundWebhooks
{
    public const EVENTS = [
        'message.received', 'deal.created', 'deal.stage', 'deal.won', 'deal.lost', 'tag.added',
        'proposal.accepted', 'proposal.declined', 'deal.stale', 'bot.handoff', 'contact.birthday', 'conversation.closed', 'survey.answered',
    ];
    public const MAX_HOOKS = 10;
    public const DISABLE_AFTER = 15;

    /** Queues one delivery per active endpoint that listens to the event. Never throws. */
    public function emit(string $event, int $userId, array $ctx): void
    {
        try {
            $hooks = OutboundWebhook::where('user_id', $userId)->where('active', true)->get()->filter(fn ($h) => in_array($event, (array) $h->events, true));
            if ($hooks->isEmpty()) {
                return;
            }
            $data = Payloads::build($ctx);
            foreach ($hooks as $hook) {
                $this->queue($hook, $event, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('Outbound webhook emit failed: ' . $e->getMessage());
        }
    }

    public function queue(OutboundWebhook $hook, string $event, array $data): WebhookDelivery
    {
        $uuid = (string) Str::uuid();
        $payload = json_encode(['id' => $uuid, 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $delivery = WebhookDelivery::create(['webhook_id' => $hook->id, 'event' => $event, 'uuid' => $uuid, 'payload' => $payload]);
        DeliverWebhookJob::dispatch($delivery->id)->onQueue('default');

        return $delivery;
    }

    public static function sign(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    /** Only public https addresses: a webhook must not reach the server's own network. */
    public static function urlAllowed(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user'])) {
            return false;
        }
        $host = $p['host'];
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
