<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Channels\ChannelAccounts;
use App\Services\Channels\ChannelException;
use Illuminate\Http\Request;

/** Settings: connect Facebook Pages (Page access token from the Meta app). Each one is an instance with channel = facebook. */
class FacebookController extends Controller
{
    public function index(Request $request)
    {
        return view('facebook.index', [
            'accounts' => $request->user()->instances()->where('channel', 'facebook')->latest()->get(),
            'callback' => rtrim((string) config('app.url'), '/') . route('webhooks.facebook', [], false),
            'configured' => config('facebook.app_secret') && config('facebook.verify_token'),
            'windowHours' => config('facebook.window_hours'),
        ]);
    }

    public function store(Request $request, ChannelAccounts $accounts)
    {
        $data = $request->validate(['token' => 'required|string|min:20|max:1000']);

        try {
            $accounts->connectFacebook($request->user(), trim($data['token']));
        } catch (ChannelException $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        return redirect()->route('facebook.index')->with('success', __('Facebook Page connected.'));
    }

    /** Checks the Page token and subscribes the Page again. */
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

        return redirect()->route('facebook.index')->with('success', __('Facebook Page removed.'));
    }

    private function own(Request $request, Instance $instance): void
    {
        abort_unless($instance->isFacebook() && (int) $instance->user_id === (int) $request->user()->id, 404);
    }
}
