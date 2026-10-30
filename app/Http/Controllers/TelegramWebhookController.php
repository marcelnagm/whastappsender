<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\Instance;
use Illuminate\Http\Request;

/** POST /api/webhooks/telegram/{instance}. Telegram signs each call with the secret we registered in setWebhook. */
class TelegramWebhookController extends Controller
{
    public function receive(Request $request, int $instance)
    {
        $bot = Instance::where('id', $instance)->where('channel', 'telegram')->first();
        abort_unless($bot && $bot->telegram_secret, 404);

        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        abort_unless(hash_equals((string) $bot->telegram_secret, $given), 401);

        $update = $request->json()->all();
        if ($update) {
            ProcessTelegramUpdateJob::dispatch($bot->id, $update)->onQueue('default');
        }

        return response()->json(['ok' => true]);
    }
}
