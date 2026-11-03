<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Channels\ChannelAccounts;
use App\Services\Channels\ChannelException;
use Illuminate\Http\Request;

/** Settings: connect the user's own mailboxes (SMTP credentials to send, IMAP to receive). Each one is an instance with channel = email. */
class EmailController extends Controller
{
    public function index(Request $request)
    {
        return view('email.index', [
            'accounts' => $request->user()->instances()->where('channel', 'email')->latest()->get(),
            'fetchCron' => config('email.fetch_cron'),
        ]);
    }

    public function store(Request $request, ChannelAccounts $accounts)
    {
        $data = $request->validate([
            'email' => 'required|email|max:190',
            'from_name' => 'nullable|string|max:120',
            'smtp_host' => 'required|string|max:190',
            'smtp_port' => 'required|integer|between:1,65535',
            'smtp_encryption' => 'required|in:ssl,tls,none',
            'smtp_username' => 'required|string|max:190',
            'smtp_password' => 'required|string|max:500',
            'imap_host' => 'nullable|string|max:190',
            'imap_port' => 'nullable|integer|between:1,65535',
            'imap_encryption' => 'nullable|in:ssl,tls,none',
            'imap_username' => 'nullable|string|max:190',
            'imap_password' => 'nullable|string|max:500',
        ]);

        try {
            $accounts->connectEmail($request->user(), $data);
        } catch (ChannelException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput($request->except(['smtp_password', 'imap_password']));
        }

        return redirect()->route('email.index')->with('success', __('E-mail account connected.'));
    }

    /** Tests the saved credentials again (and clears the error when they work). */
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

        return redirect()->route('email.index')->with('success', __('E-mail account removed.'));
    }

    private function own(Request $request, Instance $instance): void
    {
        abort_unless($instance->isEmail() && (int) $instance->user_id === (int) $request->user()->id, 404);
    }
}
