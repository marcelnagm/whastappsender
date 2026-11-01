<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Channels\ChannelAccounts;
use App\Services\Channels\ChannelException;
use App\Services\TikTok\TikTokClient;
use Illuminate\Http\Request;

/** Settings: connect TikTok Business accounts through OAuth. Each account is an instance with channel = tiktok. */
class TikTokController extends Controller
{
    public function index(Request $request)
    {
        return view('tiktok.index', [
            'accounts' => $request->user()->instances()->where('channel', 'tiktok')->latest()->get(),
            'configured' => (bool) (config('tiktok.app_id') && config('tiktok.app_secret')),
            'redirectUri' => TikTokClient::redirectUri(),
            'webhookUrl' => rtrim((string) config('app.url'), '/') . route('webhooks.tiktok', [], false),
            'windowHours' => config('tiktok.window_hours'),
        ]);
    }

    public function connect(Request $request, ChannelAccounts $accounts)
    {
        try {
            return redirect()->away($accounts->tiktokAuthUrl($request->user()));
        } catch (ChannelException $e) {
            return redirect()->route('tiktok.index')->with('error', $e->getMessage());
        }
    }

    public function callback(Request $request, ChannelAccounts $accounts)
    {
        $back = redirect()->route('tiktok.index');

        if ($accounts->tiktokStateUser((string) $request->query('state')) !== (int) $request->user()->id) {
            return $back->with('error', __('The TikTok authorization expired. Try connecting again.'));
        }

        $code = (string) ($request->query('auth_code') ?: $request->query('code'));
        if ($code === '') {
            return $back->with('error', __('TikTok did not authorize the connection: :error', ['error' => (string) $request->query('error_description', $request->query('error', '-'))]));
        }

        try {
            [, $result] = $accounts->connectTikTok($request->user(), $code);
        } catch (ChannelException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('success', $result === 'renewed' ? __('TikTok account authorized again.') : __('TikTok account connected.'));
    }

    /** Renews the tokens now; if the refresh token is gone the account must be authorised again. */
    public function refresh(Request $request, Instance $instance, ChannelAccounts $accounts)
    {
        $this->own($request, $instance);

        try {
            $accounts->renew($instance);
        } catch (ChannelException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Connection renewed.'));
    }

    public function destroy(Request $request, Instance $instance, ChannelAccounts $accounts)
    {
        $this->own($request, $instance);
        $accounts->remove($instance);

        return redirect()->route('tiktok.index')->with('success', __('TikTok account removed.'));
    }

    private function own(Request $request, Instance $instance): void
    {
        abort_unless($instance->isTiktok() && (int) $instance->user_id === (int) $request->user()->id, 404);
    }
}
