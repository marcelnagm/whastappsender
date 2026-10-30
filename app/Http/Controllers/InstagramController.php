<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Billing\Entitlements;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Settings: connect Instagram professional accounts (token from the Meta app). Each one is an instance with channel = instagram. */
class InstagramController extends Controller
{
    public function index(Request $request)
    {
        return view('instagram.index', [
            'accounts' => $request->user()->instances()->where('channel', 'instagram')->latest()->get(),
            'callback' => rtrim((string) config('app.url'), '/') . route('webhooks.instagram', [], false),
            'configured' => config('instagram.app_secret') && config('instagram.verify_token'),
            'windowHours' => config('instagram.window_hours'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|min:20|max:1000']);

        if (!config('instagram.app_secret') || !config('instagram.verify_token')) {
            return back()->withErrors(['token' => __('Instagram is not configured on this server (INSTAGRAM_APP_SECRET and INSTAGRAM_VERIFY_TOKEN).')]);
        }

        $token = trim($data['token']);
        $client = new InstagramClient($token);

        try {
            $me = $client->me();
        } catch (InstagramException $e) {
            return back()->withErrors(['token' => __('Instagram rejected this token: :error', ['error' => $e->getMessage()])]);
        }

        $igId = (string) ($me['user_id'] ?? $me['id'] ?? '');
        if ($igId === '' || Instance::where('channel', 'instagram')->where('instagram_id', $igId)->exists()) {
            return back()->withErrors(['token' => __('This Instagram account is already connected.')]);
        }

        app(Entitlements::class)->authorizeUsage($request->user(), 'instances');

        $username = (string) ($me['username'] ?? $igId);
        $instance = $request->user()->instances()->create([
            'name' => (string) ($me['name'] ?? $username) ?: $username,
            'instance_name' => 'ig-' . $request->user()->id . '-' . Str::lower(Str::random(8)),
            'status' => 'connected',
            'channel' => 'instagram',
            'instagram_token' => $token,
            'instagram_id' => $igId,
            'instagram_username' => $username,
        ]);

        try {
            $client->subscribe();
        } catch (InstagramException $e) {
            $instance->delete();

            return back()->withErrors(['token' => __('Could not subscribe the account to messages: :error', ['error' => $e->getMessage()])]);
        }

        return redirect()->route('instagram.index')->with('success', __('Instagram account connected.'));
    }

    /** Renews the 60-day token and subscribes the account again. */
    public function refresh(Request $request, Instance $instance)
    {
        $this->own($request, $instance);

        try {
            $client = InstagramClient::for($instance);
            if ($new = $client->refresh()) {
                $instance->update(['instagram_token' => $new]);
                $client = new InstagramClient($new);
            }
            $client->subscribe();
        } catch (InstagramException $e) {
            return back()->with('error', __('Could not renew the connection: :error', ['error' => $e->getMessage()]));
        }

        return back()->with('success', __('Connection renewed.'));
    }

    public function destroy(Request $request, Instance $instance)
    {
        $this->own($request, $instance);

        try {
            InstagramClient::for($instance)->unsubscribe();
        } catch (\Throwable $e) {
            // token may already be revoked: remove it anyway
        }
        $instance->delete();

        return redirect()->route('instagram.index')->with('success', __('Instagram account removed.'));
    }

    private function own(Request $request, Instance $instance): void
    {
        abort_unless($instance->isInstagram() && (int) $instance->user_id === (int) $request->user()->id, 404);
    }
}
