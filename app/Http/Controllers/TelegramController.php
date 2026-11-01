<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Channels\ChannelAccounts;
use App\Services\Channels\ChannelException;
use Illuminate\Http\Request;

/** Settings: connect Telegram bots (created with @BotFather). Each bot is an instance with channel = telegram. */
class TelegramController extends Controller
{
    public function index(Request $request)
    {
        $bots = $request->user()->instances()->where('channel', 'telegram')->latest()->get();

        return view('telegram.index', [
            'bots' => $bots,
            'webhookBase' => rtrim((string) config('app.url'), '/'),
            'httpsReady' => str_starts_with((string) config('app.url'), 'https://'),
        ]);
    }

    public function store(Request $request, ChannelAccounts $accounts)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200', 'regex:/^\d{5,}:[A-Za-z0-9_-]{30,}$/'],
        ], ['token.regex' => __('This does not look like a BotFather token (example: 123456789:AAH...).')]);

        try {
            $accounts->connectTelegram($request->user(), trim($data['token']));
        } catch (ChannelException $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        return redirect()->route('telegram.index')->with('success', __('Telegram bot connected.'));
    }

    /** Registers the webhook again (after changing APP_URL, for example). */
    public function reconnect(Request $request, Instance $instance, ChannelAccounts $accounts)
    {
        $this->own($request, $instance);

        try {
            $accounts->registerTelegramWebhook($instance);
        } catch (ChannelException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Webhook registered again.'));
    }

    public function destroy(Request $request, Instance $instance, ChannelAccounts $accounts)
    {
        $this->own($request, $instance);
        $accounts->remove($instance);

        return redirect()->route('telegram.index')->with('success', __('Telegram bot removed.'));
    }

    private function own(Request $request, Instance $instance): void
    {
        abort_unless($instance->isTelegram() && (int) $instance->user_id === (int) $request->user()->id, 404);
    }
}
