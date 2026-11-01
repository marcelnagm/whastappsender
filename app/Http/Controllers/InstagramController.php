<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Channels\ChannelAccounts;
use App\Services\Channels\ChannelException;
use Illuminate\Http\Request;

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

    public function store(Request $request, ChannelAccounts $accounts)
    {
        $data = $request->validate(['token' => 'required|string|min:20|max:1000']);

        try {
            $accounts->connectInstagram($request->user(), trim($data['token']));
        } catch (ChannelException $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        return redirect()->route('instagram.index')->with('success', __('Instagram account connected.'));
    }

    /** Renews the 60-day token and subscribes the account again. */
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

        return redirect()->route('instagram.index')->with('success', __('Instagram account removed.'));
    }

    private function own(Request $request, Instance $instance): void
    {
        abort_unless($instance->isInstagram() && (int) $instance->user_id === (int) $request->user()->id, 404);
    }
}
