<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Registers the app-level webhook (once per TikTok app). Skip it if you already set the callback URL in the TikTok developer portal. */
class TikTokRegisterWebhook extends Command
{
    protected $signature = 'tiktok:register-webhook';
    protected $description = 'Register the TikTok Business Messaging webhook URL of this app';

    public function handle(): int
    {
        if (!config('tiktok.app_id') || !config('tiktok.app_secret')) {
            $this->error('Set TIKTOK_APP_ID and TIKTOK_APP_SECRET first.');

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/') . '/api/webhooks/tiktok';
        if (!str_starts_with($url, 'https://')) {
            $this->error("APP_URL must be a public https address (got {$url}).");

            return self::FAILURE;
        }

        $response = Http::timeout((int) config('tiktok.timeout', 30))->acceptJson()->asJson()->post(config('tiktok.api_url') . config('tiktok.paths.webhook'), [
            'client_id' => config('tiktok.app_id'), 'client_secret' => config('tiktok.app_secret'),
            'event_type' => 'im_send_msg', 'callback_url' => $url,
        ]);
        $json = (array) $response->json();

        if (!$response->successful() || (int) ($json['code'] ?? 0) !== 0) {
            $this->error('TikTok refused the registration: ' . str_replace((string) config('tiktok.app_secret'), '[secret]', (string) ($json['message'] ?? 'HTTP ' . $response->status())));
            $this->line('Set the callback URL in the TikTok developer portal instead, or adjust TIKTOK_PATH_WEBHOOK to the path shown there.');

            return self::FAILURE;
        }

        $this->info("Webhook registered: {$url}");

        return self::SUCCESS;
    }
}
