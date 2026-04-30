<?php

namespace App\Jobs;

use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\User;
use App\Services\AI\GroqConversationGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SendWelcomeCampaignMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $campaignItemId;
    protected int $contactId;
    protected int $userId;

    public function __construct(int $campaignItemId, int $contactId, int $userId)
    {
        $this->campaignItemId = $campaignItemId;
        $this->contactId = $contactId;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        if (Cache::has('system_panic_mode')) {
            return;
        }

        $campaignItem = CampaignItem::find($this->campaignItemId);
        $contact = Contact::where('id', $this->contactId)
            ->where('user_id', $this->userId)
            ->first();
        $user = User::find($this->userId);

        if (!$campaignItem || !$contact || !$user) {
            return;
        }

        $instance = $user->getInstanceActive();
        if (!$instance) {
            return;
        }

        $message = $this->nextWelcomeMessage($campaignItem);

        $config = config('services.whatsapp');
        $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
        $apiKey = $config['apikey'];

        try {
            $presenceType = rand(0, 1) ? 'composing' : 'recording';
            Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/chat/sendPresence/{$instance->instance_name}", [
                'number' => $contact->contact,
                'presence' => $presenceType,
                'delay' => rand(1500, 3000),
            ]);

            if (config('app.env') !== 'local') {
                usleep(500000);
            }

            $response = Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(35)->post("{$baseUrl}/message/sendText/{$instance->instance_name}", [
                'number' => $contact->contact,
                'text' => $message,
            ]);

            if (!$response->successful()) {
                Log::warning('Falha envio welcome inicial', [
                    'campaign_item_id' => $this->campaignItemId,
                    'contact_id' => $this->contactId,
                    'status' => $response->status(),
                ]);
                return;
            }

            Redis::sadd("welcome:pending_reply:user:{$this->userId}", $contact->contact);
            Redis::hset("welcome:contact_campaign:user:{$this->userId}", $contact->contact, (string) $campaignItem->id);
            Redis::expire("welcome:pending_reply:user:{$this->userId}", 60 * 60 * 24 * 15);
            Redis::expire("welcome:contact_campaign:user:{$this->userId}", 60 * 60 * 24 * 15);
        } catch (\Throwable $e) {
            Log::error('Erro ao enviar welcome inicial', [
                'campaign_item_id' => $this->campaignItemId,
                'contact_id' => $this->contactId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nextWelcomeMessage(CampaignItem $campaignItem): string
    {
        $poolKey = "welcome:pool:campaign_item:{$campaignItem->id}";

        if ((int) Redis::llen($poolKey) === 0) {
            $messages = app(GroqConversationGenerator::class)->generate(
                50,
                'primeiro contato whatsapp',
                'Gere 50 mensagens curtas de primeiro contato para vendas no WhatsApp (em pt-BR).
O objetivo é abrir o loop de conversação e gerar uma resposta do lead.

Diretrizes de Vendas (Copywriting):
1. Estrutura Obrigatória: Saudação + Gancho (focado em uma dor ou benefício) + Pergunta de Baixa Fricção (ex: "Faz sentido para você?", "É você que cuida disso?", "Podemos falar 1 minuto?").
2. Tom: Humano, direto e consultivo. É terminantemente proibido soar como telemarketing genérico ou usar emojis em excesso.
3. Variações de "Bom dia": Devem ser ganchos matinais de negócios (ex: "Bom dia, [Nome]! Estava analisando o mercado de vocês e notei...").

Estrutura de Dados Exigida:
Retorne EXCLUSIVAMENTE um array JSON contendo objetos com as chaves: "categoria" (ex: "frio", "follow-up", "matinal") e "mensagem" (o texto da abordagem).

Restrição Estrita de Saída:
Não inclua introduções, conclusões ou blocos de código (como ```json). A saída deve começar com [ e terminar com ], sendo um JSON perfeitamente válido para parse imediato.'
            );

            if (!is_array($messages) || empty($messages)) {
                $messages = [trim((string) $campaignItem->text)];
            }

            foreach ($messages as $message) {
                $value = trim((string) $message);
                if ($value === '') {
                    continue;
                }
                Redis::rpush($poolKey, $value);
            }
            Redis::expire($poolKey, 60 * 60 * 24 * 7);
        }

        $message = Redis::lpop($poolKey);
        if ($message) {
            Redis::rpush($poolKey, $message);
        }

        return trim((string) $message) ?: trim((string) $campaignItem->text);
    }
}
