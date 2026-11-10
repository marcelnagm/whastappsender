<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Billing\Entitlements;
use App\Services\Webchat\Webchat;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Settings: the chat widgets of the account's websites. Each one is an instance with channel = webchat. */
class WebchatController extends Controller
{
    public function index(Request $request)
    {
        $widgets = $request->user()->instances()->where('channel', 'webchat')->latest()->get();

        return view('webchat.index', ['widgets' => $widgets, 'cfg' => fn (Instance $i) => Webchat::config($i)]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:80']);
        app(Entitlements::class)->authorizeUsage($request->user(), 'instances');

        $request->user()->instances()->create([
            'name' => $d['name'], 'instance_name' => 'wc' . Str::lower(Str::random(24)), 'status' => 'connected', 'channel' => 'webchat',
            'webchat_config' => Webchat::defaults(),
        ]);

        return redirect()->route('webchat.index')->with('success', __('Chat widget created. Copy the snippet to your site.'));
    }

    public function update(Request $request, Instance $instance)
    {
        $this->own($request, $instance);
        $d = $request->validate([
            'name' => 'required|string|max:80', 'title' => 'nullable|string|max:60', 'welcome' => 'nullable|string|max:300',
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'origins' => 'nullable|string|max:500',
        ]);
        $instance->update(['name' => $d['name'], 'webchat_config' => [
            'title' => $d['title'] ?? '', 'welcome' => $d['welcome'] ?? '', 'color' => Webchat::color($d['color'] ?? null), 'origins' => $d['origins'] ?? '',
            'ask_contact' => $request->boolean('ask_contact'), 'active' => $request->boolean('active'),
        ]]);

        return redirect()->route('webchat.index')->with('success', __('Saved.'));
    }

    public function destroy(Request $request, Instance $instance)
    {
        $this->own($request, $instance);
        $instance->delete();

        return redirect()->route('webchat.index')->with('success', __('Chat widget removed.'));
    }

    private function own(Request $request, Instance $instance): void
    {
        abort_unless($instance->isWebchat() && (int) $instance->user_id === (int) $request->user()->id, 404);
    }
}
