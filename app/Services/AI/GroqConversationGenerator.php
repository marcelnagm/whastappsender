<?php

namespace App\Services\AI;

use App\Services\Contracts\ConversationGeneratorInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqConversationGenerator implements ConversationGeneratorInterface
{
    public function generate(int $count, string $topic, $prompt = null): array
    {
        $apiKey = config('services.groq.key'); // From config/services.php

        if (!$prompt) {
            $prompt = "Act as a native English speaker on WhatsApp. Generate an informal back-and-forth dialogue of {$count} alternating messages about '{$topic}'. "
                . "Use light slang, casual abbreviations (u, rn, tbh) and occasionally a small typo. "
                . "Return ONLY a JSON array of strings. Do not add explanations.";
        }

        try {
            $response = Http::withToken($apiKey)
                ->retry(3, 700)
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
                $responseBody = (string) $response->body();
                Log::error('Groq API returned HTTP error', [
                    'status' => $response->status(),
                    'reason' => $response->reason(),
                    'body_preview' => mb_substr($responseBody, 0, 600),
                ]);
                throw new \Exception("Groq API Error HTTP " . $response->status());
            }

            $json = $response->json();
            $content = (string) ($json['choices'][0]['message']['content'] ?? '');
            $messages = $this->decodeMessagesFromContent($content);

            if (empty($messages)) {
                Log::warning("Failed to decode Groq JSON. Raw content: " . $content);
                return $this->fallbackScript($count);
            }

            return array_slice($messages, 0, $count);
        } catch (\Exception $e) {
            Log::error("Warmup Generator error: " . $e->getMessage(), [
                'exception' => get_class($e),
            ]);
            return $this->fallbackScript($count);
        }
    }

    private function decodeMessagesFromContent(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        // Tentativa 1: conteúdo já é JSON puro.
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $this->extractMessages($decoded);
        }

        // Tentativa 2: resposta veio em bloco markdown ```json ... ```.
        if (preg_match('/```(?:json)?\s*(\[.*\])\s*```/is', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $this->extractMessages($decoded);
            }
        }

        // Tentativa 3: extrai o primeiro array JSON dentro do texto.
        if (preg_match('/\[[\s\S]*\]/', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $this->extractMessages($decoded);
            }
        }

        return [];
    }

    private function extractMessages(array $decoded): array
    {
        if (isset($decoded['messages']) && is_array($decoded['messages'])) {
            return $this->normalizeMessages($decoded['messages']);
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $this->normalizeMessages($decoded['data']);
        }

        return $this->normalizeMessages($decoded);
    }

    private function normalizeMessages(array $messages): array
    {
        $normalized = [];
        foreach ($messages as $message) {
            $value = trim((string) $message);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    private function fallbackScript(int $count): array
    {
        return array_fill(0, $count, "Sounds good — let's do it that way!");
    }
}
