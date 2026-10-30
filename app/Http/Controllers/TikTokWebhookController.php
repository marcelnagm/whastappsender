<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTikTokEventJob;
use Illuminate\Http\Request;

/** Webhook of the TikTok app. Header `Tiktok-Signature: t=<unix time>,s=<hex hmac-sha256 of "t.body" with the app secret>`. */
class TikTokWebhookController extends Controller
{
    public function receive(Request $request)
    {
        $secret = (string) config('tiktok.app_secret');
        abort_unless($secret !== '', 503);

        $parts = [];
        foreach (explode(',', (string) $request->header('Tiktok-Signature', '')) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k] = $v;
        }
        $t = (int) ($parts['t'] ?? 0);
        $given = (string) ($parts['s'] ?? '');

        $expected = hash_hmac('sha256', $t . '.' . $request->getContent(), $secret);
        abort_unless($t > 0 && hash_equals($expected, $given), 401);
        abort_unless(abs(time() - $t) <= (int) config('tiktok.signature_tolerance', 300), 401);

        $body = $request->json()->all();
        if ($body) {
            ProcessTikTokEventJob::dispatch($body)->onQueue('default');
        }

        return response()->json(['ok' => true]); // TikTok expects HTTP 200 and retries otherwise
    }
}
