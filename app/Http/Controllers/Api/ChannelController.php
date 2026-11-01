<?php

namespace App\Http\Controllers\Api;

use App\Models\Instance;
use App\Services\Billing\Entitlements;
use App\Services\Channels\ChannelAccounts;
use App\Services\Channels\ChannelException;
use App\Services\Stats\ChannelStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Telegram / Instagram / TikTok accounts (WhatsApp numbers stay in InstanceController). */
class ChannelController extends ApiController
{
    private const CHANNELS = ['telegram', 'instagram', 'tiktok'];

    public function index(Request $request)
    {
        $channel = $request->input('channel');

        $rows = $this->scopedToUser(Instance::query())
            ->when(in_array($channel, array_merge(self::CHANNELS, ['whatsapp']), true), fn ($q) => $channel === 'whatsapp'
                ? $q->where(fn ($w) => $w->whereNull('channel')->orWhere('channel', 'whatsapp'))
                : $q->where('channel', $channel))
            ->orderBy('id')->get()
            ->map(fn (Instance $i) => $this->present($i));

        return $this->success($rows);
    }

    public function connectTelegram(Request $request, ChannelAccounts $accounts)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:200', 'regex:/^\d{5,}:[A-Za-z0-9_-]{30,}$/']]);

        return $this->run(fn () => $this->success($this->present($accounts->connectTelegram(Auth::user(), trim($data['token']))), 'Telegram bot connected.', 201));
    }

    public function connectInstagram(Request $request, ChannelAccounts $accounts)
    {
        $data = $request->validate(['token' => 'required|string|min:20|max:1000']);

        return $this->run(fn () => $this->success($this->present($accounts->connectInstagram(Auth::user(), trim($data['token']))), 'Instagram account connected.', 201));
    }

    /** TikTok needs a browser: the person opens this URL, authorises, and the callback stores the account. */
    public function tiktokUrl(ChannelAccounts $accounts)
    {
        return $this->run(fn () => $this->success(['url' => $accounts->tiktokAuthUrl(Auth::user())]));
    }

    public function renew(Instance $instance, ChannelAccounts $accounts)
    {
        $this->authorizeChannel($instance);

        return $this->run(function () use ($accounts, $instance) {
            $accounts->renew($instance);

            return $this->success($this->present($instance->fresh()), 'Connection renewed.');
        });
    }

    public function destroy(Instance $instance, ChannelAccounts $accounts)
    {
        $this->authorizeChannel($instance);
        $accounts->remove($instance);

        return $this->success(null, 'Channel account removed.');
    }

    public function stats(Request $request, ChannelStats $stats)
    {
        return $this->success($stats->summary(Auth::user(), (int) $request->input('days', 30)));
    }

    private function authorizeChannel(Instance $instance): void
    {
        abort_unless(!$instance->isWhatsapp(), 422, 'Use the instances endpoints for WhatsApp numbers.');
        $this->authorizeOwner($instance);
    }

    private function run(\Closure $fn)
    {
        try {
            return $fn();
        } catch (ChannelException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    private function present(Instance $i): array
    {
        return [
            'id' => $i->id, 'name' => $i->name, 'channel' => $i->channelKey(),
            'status' => $i->status, 'account' => $i->instance_name,
        ];
    }
}
