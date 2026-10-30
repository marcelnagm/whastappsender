<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Billing\Entitlements;
use App\Services\TikTok\TikTokClient;
use App\Services\TikTok\TikTokException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

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

    public function connect(Request $request)
    {
        if (!config('tiktok.app_id') || !config('tiktok.app_secret')) {
            return redirect()->route('tiktok.index')->with('error', __('TikTok is not configured on this server (TIKTOK_APP_ID and TIKTOK_APP_SECRET).'));
        }

        $state = Crypt::encryptString(json_encode(['u' => $request->user()->id, 'exp' => now()->addMinutes(15)->timestamp, 'n' => Str::random(12)]));

        return redirect()->away(TikTokClient::authUrl($state));
    }

    public function callback(Request $request)
    {
        $back = redirect()->route('tiktok.index');

        try {
            $state = json_decode(Crypt::decryptString((string) $request->query('state')), true);
        } catch (\Throwable $e) {
            $state = null;
        }
        if (!is_array($state) || (int) ($state['u'] ?? 0) !== (int) $request->user()->id || (int) ($state['exp'] ?? 0) < time()) {
            return $back->with('error', __('The TikTok authorization expired. Try connecting again.'));
        }

        $code = (string) ($request->query('auth_code') ?: $request->query('code'));
        if ($code === '') {
            return $back->with('error', __('TikTok did not authorize the connection: :error', ['error' => (string) $request->query('error_description', $request->query('error', '-'))]));
        }

        try {
            $tokens = TikTokClient::exchange($code);
        } catch (TikTokException $e) {
            return $back->with('error', __('TikTok rejected the authorization: :error', ['error' => $e->getMessage()]));
        }

        $openId = (string) ($tokens['open_id'] ?? $tokens['business_id'] ?? '');
        if ($openId === '' || empty($tokens['access_token'])) {
            return $back->with('error', __('TikTok rejected the authorization: :error', ['error' => 'no account returned']));
        }

        $existing = Instance::where('channel', 'tiktok')->where('tiktok_open_id', $openId)->first();
        if ($existing && (int) $existing->user_id !== (int) $request->user()->id) {
            return $back->with('error', __('This TikTok account is already connected.'));
        }

        if ($existing) { // authorising again renews the tokens
            TikTokClient::storeTokens($existing, $tokens);
            $existing->update(['status' => 'connected']);

            return $back->with('success', __('TikTok account authorized again.'));
        }

        app(Entitlements::class)->authorizeUsage($request->user(), 'instances');

        $name = (string) ($tokens['display_name'] ?? $tokens['username'] ?? 'TikTok ' . substr($openId, -6));
        $instance = $request->user()->instances()->create([
            'name' => $name,
            'instance_name' => 'tt-' . $request->user()->id . '-' . Str::lower(Str::random(8)),
            'status' => 'connected',
            'channel' => 'tiktok',
            'tiktok_open_id' => $openId,
            'tiktok_username' => $name,
        ]);
        TikTokClient::storeTokens($instance, $tokens);

        return $back->with('success', __('TikTok account connected.'));
    }

    /** Renews the tokens now; if the refresh token is gone the account must be authorised again. */
    public function refresh(Request $request, Instance $instance)
    {
        $this->own($request, $instance);

        try {
            TikTokClient::for($instance)->refreshIfNeeded(true);
        } catch (TikTokException $e) {
            return back()->with('error', __('Could not renew the connection: :error', ['error' => $e->getMessage()]));
        }

        return back()->with('success', __('Connection renewed.'));
    }

    public function destroy(Request $request, Instance $instance)
    {
        $this->own($request, $instance);
        $instance->delete();

        return redirect()->route('tiktok.index')->with('success', __('TikTok account removed.'));
    }

    private function own(Request $request, Instance $instance): void
    {
        abort_unless($instance->isTiktok() && (int) $instance->user_id === (int) $request->user()->id, 404);
    }
}
