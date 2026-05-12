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

        return redirect('/')->with('success', "Account created. Contact 5595981110695 to activate it.");
    }

    private function generateFollowUp($data)
    {
        try {
            // 1. Garantir o Registro do Contato (Usa updateOrCreate para evitar erro de duplicata)
            $contato = Contact::updateOrCreate(
                ['contact' => $data['ddi'].$data['phone']], // Chave de busca (evita Exception de Unique Index)
                [
                    'name'    => $data['name'] . ' Site',
                    'email'   => $data['email'],
                    'user_id' => 1 // ID do Admin/Sistema
                ]
            );

            // 2. Validação de Variável de Ambiente
            $campaignItemId = env('WHATSAPP_MESSAGE_UP');
            if (!$campaignItemId) {
                Log::error("Follow-up aborted: WHATSAPP_MESSAGE_UP not set in .env");
                return;
            }

            $item = CampaignItem::find($campaignItemId);
            if (!$item) {
                Log::error("Follow-up aborted: CampaignItem {$campaignItemId} not found.");
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
            Log::critical("Critical follow-up failure for {$data['email']}: " . $e->getMessage());
        }
    }
}
