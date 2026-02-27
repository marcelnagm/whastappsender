<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function receive(Request $request)
    {
        try {
            $payload = $request->all();
            $event = $payload['event'] ?? null;

            if ($event === 'messages.update') {
                $data = $payload['data'] ?? [];
                
                // Baseado no seu log real: o ID está em 'messageId' e o status em 'status'
                $messageId = $data['keyId'] ?? null;
                $statusRaw = $data['status'] ?? null;

                if ($messageId) {
                    // Converter status para minúsculo para manter padrão no banco (READ -> read)
                    $statusName = strtolower($statusRaw);

                    // Busca o registro pelo message_id salvo no disparo
                    $job = WhatsappJob::where('message_id', $messageId)->first();

                    if ($job) {
                        $job->update([
                            'evolution_status' => $statusName
                        ]);
                        Log::info("Job {$messageId} atualizado para: {$statusName}");
                    } else {
                        Log::warning("Webhook recebeu ID {$messageId}, mas não encontrou no banco.");
                    }
                }
            }

            return response()->json(['status' => 'success'], 200);

        } catch (\Exception $e) {
            Log::error("Erro no processamento do Webhook: " . $e->getMessage());
            return response()->json(['status' => 'error_logged'], 200);
        }
    }
}