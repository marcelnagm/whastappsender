<?php

namespace App\Services\AI\Llm;

/** Users type the endpoint of Ollama / custom providers: keep the server from being aimed at its own network. */
class UrlGuard
{
    public static function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new LlmException(__('Invalid endpoint URL.'));
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array) @gethostbynamel($host);
        foreach ($ips as $ip) {
            // cloud metadata / link-local is never acceptable
            if (str_starts_with($ip, '169.254.')) {
                throw new LlmException(__('This endpoint address is not allowed.'));
            }

            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            if (!$public && !config('ai.allow_private_hosts')) {
                throw new LlmException(__('Private-network endpoints are disabled on this server (AI_ALLOW_PRIVATE_HOSTS).'));
            }
        }
    }
}
