<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\RegisterRequest;
use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\WhatsappJob;
use Exception;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    /**
     * Display register page.
     * 
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        return view('auth.register');
    }

    /**
     * Handle account registration request
     * 
     * @param RegisterRequest $request
     * 
     * @return \Illuminate\Http\Response
     */
    public function register(RegisterRequest $request)
    {

        $data = $request->validated();
        //  $user = User::create($data);
        $this->generateFollowUp($data);

        // auth()->login($user);

        return redirect('/')->with('success', "Conta Criada, entre em contato 5595981110695 para ativa-la.");
    }

    private function generateFollowUp($data)
    {
        try {
            // 1. Garantir o Registro do Contato (Usa updateOrCreate para evitar erro de duplicata)
            $contato = Contact::updateOrCreate(
                ['contact' => $data['phone']], // Chave de busca (evita Exception de Unique Index)
                [
                    'name'    => $data['name'] . ' Site',
                    'email'   => $data['email'],
                    'user_id' => 1 // ID do Admin/Sistema
                ]
            );

            // 2. Validação de Variável de Ambiente
            $campaignItemId = env('WHATSAPP_MESSAGE_UP');
            if (!$campaignItemId) {
                Log::error("Follow-up abortado: WHATSAPP_MESSAGE_UP não configurado no .env");
                return;
            }

            $item = CampaignItem::find($campaignItemId);
            if (!$item) {
                Log::error("Follow-up abortado: CampaignItem {$campaignItemId} não encontrado.");
                return;
            }

            // 3. Persistência do Job
            $now = now();
            $job = new WhatsappJob([
                'user_id'          => $item->user_id,
                'campaign_id'      => $item->campaign_id,
                'campaign_item_id' => $item->id,
                'contact_id'       => $contato->id,
                'status'           => 'pendente',
                'endpoint'         => $item->getOperation(),
                'payload'          => json_encode($item->generate($contato->id)),
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            if ($job->save()) {
                // 4. Despacho para Fila (Queue) - Essencial para não travar o Request do usuário
                \App\Jobs\EnviarMensagemJob::dispatch($job)->onQueue('default');
            }
        } catch (\Exception $e) {
            // SILENT FAIL: Registra o erro no log, mas não interrompe o fluxo do usuário.
            // A verdade útil: O cadastro do usuário é mais importante que o follow-up.
            Log::critical("Falha crítica no Follow-up de {$data['email']}: " . $e->getMessage());
        }
    }
}
