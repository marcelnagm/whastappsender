<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessEvolutionWebhookJob;

class WebhookController extends Controller
{
    public function receive(Request $request)
    {
        // Validação mínima para não processar lixo
        $payload = $request->all();
        
        if (!isset($payload['event'])) {
            return response()->json(['status' => 'ignored'], 200);
        }

        // Despacha para a fila do Redis
        ProcessEvolutionWebhookJob::dispatch($payload);

        return response()->json(['status' => 'queued'], 200);
    }
}