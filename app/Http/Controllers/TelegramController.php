<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Billing\Entitlements;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200', 'regex:/^\d{5,}:[A-Za-z0-9_-]{30,}$/'],
        ], ['token.regex' => __('This does not look like a BotFather token (example: 123456789:AAH...).')]);

        $token = trim($data['token']);

        try {
            $me = (new TelegramClient($token))->getMe();
        } catch (TelegramException $e) {
            return back()->withInput(['token' => ''])->withErrors(['token' => __('Telegram rejected this token: :error', ['error' => $e->getMessage()])]);
        }

        $username = (string) ($me['username'] ?? '');
        if ($username === '' || Instance::where('channel', 'telegram')->where('telegram_username', $username)->exists()) {
            return back()->withErrors(['token' => __('This bot is already connected.')]);
        }

        app(Entitlements::class)->authorizeUsage($request->user(), 'instances');

        $instance = $request->user()->instances()->create([
            'name' => (string) ($me['first_name'] ?? $username),
            'instance_name' => 'tg-' . $request->user()->id . '-' . Str::lower(Str::random(8)),
            'status' => 'connected',
            'channel' => 'telegram',
            'telegram_token' => $token,
            'telegram_username' => $username,
            'telegram_secret' => bin2hex(random_bytes(24)),
        ]);

        try {
            $this->register($instance);
        } catch (TelegramException $e) {
            $instance->delete();

            return back()->withErrors(['token' => __('Could not register the webhook: :error', ['error' => $e->getMessage()])]);
        }

        return redirect()->route('telegram.index')->with('success', __('Telegram bot connected.'));
    }

    /** Registers the webhook again (after changing APP_URL, for example). */
    public function reconnect(Request $request, Instance $instance)
    {
        $this->own($request, $instance);

        try {
            $this->register($instance);
        } catch (TelegramException $e) {
            return back()->with('error', __('Could not register the webhook: :error', ['error' => $e->getMessage()]));
        }

        return back()->with('success', __('Webhook registered again.'));
    }

    public function destroy(Request $request, Instance $instance)
    {
        $this->own($request, $instance);

        try {
            TelegramClient::for($instance)->deleteWebhook();
        } catch (\Throwable $e) {
            // the bot may have been revoked at BotFather: remove it anyway
        }
        $instance->delete();

        return redirect()->route('telegram.index')->with('success', __('Telegram bot removed.'));
    }

    private function register(Instance $instance): void
    {
        $url = rtrim((string) config('app.url'), '/') . route('webhooks.telegram', ['instance' => $instance->id], false);
        if (!str_starts_with($url, 'https://')) {
            throw new TelegramException(__('Telegram only delivers messages to https addresses. Set APP_URL to your public https domain.'));
        }

        TelegramClient::for($instance)->setWebhook($url, (string) $instance->telegram_secret);
    }

    private function own(Request $request, Instance $instance): void
    {
        abort_unless($instance->isTelegram() && (int) $instance->user_id === (int) $request->user()->id, 404);
    }
}
