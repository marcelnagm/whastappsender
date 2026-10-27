<?php

namespace App\Services\Instances;

use Illuminate\Support\Facades\Http;

/** Asks the Evolution API what it knows. Never throws: "cannot tell" is a normal answer (null). */
class EvolutionStatus
{
    private function base(): string
    {
        $c = config('services.whatsapp');

        return "{$c['protocol']}://{$c['url']}:{$c['port']}";
    }

    private function http()
    {
        return Http::withHeaders(['apikey' => (string) config('services.whatsapp.apikey')])->acceptJson()->timeout(8);
    }

    /**
     * @return string|null 'open' | 'connecting' | 'close' | 'missing' (unknown to Evolution), or null when Evolution
     *                     could not be asked (down, timeout, bad key): the caller must not treat that as "disconnected".
     */
    public function state(string $instanceName): ?string
    {
        try {
            $response = $this->http()->get($this->base() . '/instance/connectionState/' . rawurlencode($instanceName));
        } catch (\Throwable $e) {
            return null;
        }

        if ($response->status() === 404) {
            return 'missing';
        }
        if (!$response->successful()) {
            return null;
        }

        $state = $response->json('instance.state') ?? $response->json('state');

        return is_string($state) ? strtolower($state) : null;
    }

    /** Is the Evolution API itself answering? (any HTTP answer below 500, even a refused key, means "up") */
    public function reachable(): bool
    {
        try {
            return $this->http()->get($this->base() . '/instance/fetchInstances')->status() < 500;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
