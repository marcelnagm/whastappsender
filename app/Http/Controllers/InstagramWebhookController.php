<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInstagramEventJob;
use Illuminate\Http\Request;

/**
 * Meta webhook for every connected Instagram account.
 * GET  = subscription handshake (hub.verify_token must match INSTAGRAM_VERIFY_TOKEN).
 * POST = events, signed with the app secret in X-Hub-Signature-256.
 */
class InstagramWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $expected = (string) config('instagram.verify_token');
        $given = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));

        abort_unless($expected !== '' && $mode === 'subscribe' && hash_equals($expected, $given), 403);

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge', '')), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request)
    {
        $secret = (string) config('instagram.app_secret');
        abort_unless($secret !== '', 503);

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(hash_equals($expected, $signature), 401);

        $payload = $request->json()->all();
        if (($payload['object'] ?? '') === 'instagram' && !empty($payload['entry'])) {
            ProcessInstagramEventJob::dispatch($payload)->onQueue('default');
        }

        return response()->json(['ok' => true]);
    }
}
