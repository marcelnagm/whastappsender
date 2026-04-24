<?php

namespace App\Services\AI;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqAgentResponder
{
    public function generateReply(User $user, array $history): array
    {
        $apiKey = config('services.groq.key');
        $model = $user->ai_model ?: 'llama-3.3-70b-versatile';
        $temperature = (float) ($user->ai_temperature ?? 0.7);
        $maxTokens = (int) ($user->ai_max_tokens ?? 512);

        $systemPrompt = $user->ai_system_prompt ?: $this->defaultSystemPrompt();

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($history as $item) {
            $role = $item['role'] ?? 'user';
            $content = trim((string) ($item['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'max_completion_tokens' => $maxTokens,
                    'top_p' => 1,
                    'stream' => false,
                ]);

            if ($response->failed()) {
                throw new \Exception('Groq API Error: ' . $response->body());
            }

            $json = $response->json();
            $content = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
            if ($content === '') {
                $content = $this->fallbackReply();
            }

            return [
                'reply' => $content,
                'model' => $model,
                'raw' => $json,
                'tokens_in' => $json['usage']['prompt_tokens'] ?? null,
                'tokens_out' => $json['usage']['completion_tokens'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('Erro no GroqAgentResponder: ' . $e->getMessage());

            return [
                'reply' => $this->fallbackReply(),
                'model' => $model,
                'raw' => ['error' => $e->getMessage()],
                'tokens_in' => null,
                'tokens_out' => null,
            ];
        }
    }

    private function defaultSystemPrompt(): string
    {
        return 'Você é um atendente brasileiro, objetivo e educado. '
            . 'Responda em pt-BR, com mensagens curtas, claras e úteis. '
            . 'Quando faltar contexto, faça uma pergunta curta para avançar.';
    }

    private function fallbackReply(): string
    {
        return 'Perfeito, recebi sua mensagem. Pode me dar mais um detalhe para eu te ajudar melhor?';
    }
}
