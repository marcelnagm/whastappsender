<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use Illuminate\Http\Request;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WebhookController extends Controller
{
    public function receive(Request $request)
    {
        try {
            $payload = $request->all();
            $event = $payload['event'] ?? null;
            $instanceName = $payload['instance'] ?? null;


            switch ($event) {
                // EVENTO 1: Atualização de Status da Instância (O que você pediu)
                case 'connection.update':
                    if (!$instanceName) {
                        return response()->json(['status' => 'no_instance_provided'], 200);
                    }
        
                    $state = $payload['data']['state'] ?? null; // "open", "close", "connecting"

                    $instance = Instance::where('instance_name', $instanceName)->first();

                    if ($instance) {
                        $oldStatus = $instance->status;
                        // Mapeia o status da Evolution para o seu banco
                        $newStatus = ($state === 'open') ? 'connected' : 'disconnected';

                        if ($oldStatus !== $newStatus) {
                            $instance->update(['status' => $newStatus]);

                            // REGRA: Notificar transição Conectado -> Desconectado
                            if ($oldStatus === 'connected' && $newStatus === 'disconnected') {
                                $this->sendDisconnectedEmail($instance);
                            }
                        }
                    }
                    break;

                // EVENTO 2: Atualização de Status da Mensagem (Seu código original)
                case 'messages.update':
                    $data = $payload['data'] ?? [];
                    $messageId = $data['keyId'] ?? null;
                    $statusName = strtolower($data['status'] ?? '');

                    if ($messageId) {
                        $job = WhatsappJob::where('message_id', $messageId)->first();
                        if ($job) {
                            $job->update(['evolution_status' => $statusName]);
                        }
                    }
                    break;
            }

            return response()->json(['status' => 'success'], 200);
        } catch (\Exception $e) {
            Log::error("Erro Webhook Evolution [{$instanceName}]: " . $e->getMessage());
            return response()->json(['status' => 'error_logged'], 200);
        }
    }

    /**
     * Método auxiliar para envio de e-mail (Centralize aqui para não poluir o receive)
     */
    private function sendDisconnectedEmail($instance)
    {
        try {
            // Use queue() para não travar o Webhook
            Mail::to($instance->user->email)->queue(new \App\Mail\InstanceDisconnectedMail($instance));
            Log::info("E-mail de desconexão enviado para o usuário da instância: {$instance->instance_name}");
        } catch (\Exception $e) {
            Log::error("Falha ao disparar e-mail de desconexão: " . $e->getMessage());
        }
    }
}
