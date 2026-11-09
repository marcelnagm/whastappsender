<?php

namespace App\Services\AI;

use App\Models\AiProfile;
use App\Services\AI\Llm\LlmClient;
use App\Services\AI\Llm\LlmException;
use Illuminate\Support\Facades\Log;

/** Builds the answer of an AI agent for a conversation. Replaces the Groq-only responder. */
class AgentResponder
{
    public const DEFAULT_PROMPT = 'You are a concise, polite support agent on WhatsApp. '
        . 'Reply in clear, short messages. '
        . 'When context is missing, ask one brief question to move forward.';

    public function __construct(private LlmClient $llm)
    {
    }

    /**
     * @param array<int, array{role:string,content:string}> $history oldest first
     * @return array{reply:string,error:?string,provider:string,model:string,tokens_in:?int,tokens_out:?int,raw:?array}
     */
    public function generateReply(AiProfile $profile, array $history): array
    {
        $base = ['provider' => $profile->provider, 'model' => $profile->model];

        try {
            $system = trim((string) $profile->system_prompt) ?: self::DEFAULT_PROMPT;
            if ($profile->use_knowledge) {
                $system .= app(\App\Services\Rag\KnowledgeContext::class)->forHistory($profile, $history);
            }
            $result = $this->llm->chat($profile, $system, $history);
            if ($profile->user_id) {
                AiUsageLog::record((int) $profile->user_id, 'reply', $profile->exists ? $profile : null, $profile->provider, $profile->model, $result['tokens_in'] ?? null, $result['tokens_out'] ?? null);
            }

            return $base + ['reply' => $result['reply'], 'error' => null, 'tokens_in' => $result['tokens_in'], 'tokens_out' => $result['tokens_out'], 'raw' => $result['raw']];
        } catch (LlmException $e) {
            Log::warning("AI agent #{$profile->id} ({$profile->provider}/{$profile->model}) failed: " . $e->getMessage());

            // nothing is sent to the customer on a failure: a canned "got it" would only confuse the conversation
            return $base + ['reply' => '', 'error' => $e->getMessage(), 'tokens_in' => null, 'tokens_out' => null, 'raw' => null];
        }
    }
}
