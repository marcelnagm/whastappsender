<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessEvolutionWebhookJob;

class WebhookController extends Controller
{
    public function receive(Request $request)
    {
        // Minimal validation — ignore junk payloads
        $payload = $request->all();
        
        if (!isset($payload['event'])) {
            return response()->json(['status' => 'ignored'], 200);
        }

        // Dispatch to the Redis queue
        ProcessEvolutionWebhookJob::dispatch($payload);

        return response()->json(['status' => 'queued'], 200);
    }
}