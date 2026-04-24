<?php

namespace App\Services\AI;

use App\Services\Contracts\ConversationGeneratorInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqConversationGenerator implements ConversationGeneratorInterface
{
    public function generate(int $count, string $topic, $prompt  = null): array
    {
        $apiKey = config('services.groq.key'); // Puxa do config/services.php

        if($prompt)
        $prompt = "Atue como um brasileiro nativo no WhatsApp. Gere um diálogo informal de {$count} mensagens alternadas sobre '{$topic}'. "
            . "Use gírias leves, abreviações (vc, tbm, blz) e ocasionalmente um erro de digitação. "
            . "Retorne APENAS um array JSON de strings. Não adicione explicações.";

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post("https://api.groq.com/openai/v1/chat/completions", [
                    "model" => "llama-3.3-70b-versatile",
                    "messages" => [
                        ["role" => "user", "content" => $prompt]
                    ],
                    "temperature" => 1,
                    "max_completion_tokens" => 2048, // Aumentado para suportar 40 frases longas
                    "top_p" => 1,
                    "stream" => false, // OBRIGATÓRIO: No PHP/Jobs usamos false para pegar a resposta cheia
                ]);

            if ($response->failed()) {
                throw new \Exception("Groq API Error: " . $response->body());
            }

            $json = $response->json();
            $content = $json['choices'][0]['message']['content'] ?? '';

            // Sanitização para garantir que pegamos apenas o JSON caso a IA mande lixo de texto
            $content= json_decode($content);
            if (!is_array($content)) {
                Log::warning("Falha ao decodificar JSON da Groq. Conteúdo: " . $content);
                return $this->fallbackScript($count);
            }

            return $content;
        } catch (\Exception $e) {
            Log::error("Erro no Warmup Generator: " . $e->getMessage());
            return $this->fallbackScript($count);
        }
    }

    private function fallbackScript(int $count): array
    {
        return array_fill(0, $count, "Beleza, combinamos assim!");
    }
}
