<?php

namespace App\Services\Bots;

use App\Models\TypebotSetting;
use Illuminate\Support\Facades\Http;

/** The two calls of Typebot's public Chat API. Throws on any failure so the caller can fall back to a person. */
class TypebotClient
{
    public function start(TypebotSetting $cfg, array $variables = [], bool $onlyRegister = false): array
    {
        $body = ['textBubbleContentFormat' => 'markdown'];
        if ($variables) {
            $body['prefilledVariables'] = $variables;
        }
        if ($onlyRegister) {
            $body['isOnlyRegistering'] = true;
        }

        return $this->post($this->base($cfg) . '/api/v1/typebots/' . rawurlencode((string) $cfg->public_id) . '/startChat', $body);
    }

    public function continue(TypebotSetting $cfg, string $sessionId, string $text): array
    {
        return $this->post($this->base($cfg) . '/api/v1/sessions/' . rawurlencode($sessionId) . '/continueChat', ['message' => ['type' => 'text', 'text' => $text], 'textBubbleContentFormat' => 'markdown']);
    }

    private function base(TypebotSetting $cfg): string
    {
        return rtrim((string) $cfg->base_url, '/');
    }

    private function post(string $url, array $body): array
    {
        $res = Http::timeout(15)->acceptJson()->post($url, $body);
        if ($res->status() === 404) {
            throw new TypebotSessionGone('Typebot: not found');
        }
        $res->throw();

        return (array) $res->json();
    }
}
