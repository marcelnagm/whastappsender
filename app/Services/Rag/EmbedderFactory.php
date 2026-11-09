<?php

namespace App\Services\Rag;

use App\Models\AiProfile;

/** Chooses the embedder of an account: hosted when a key is at hand, the built-in one otherwise. */
class EmbedderFactory
{
    public function for(int $userId): Embedder
    {
        $models = (array) config('rag.embedding_models', []);

        // 1) a key the account already saved in one of its AI agents
        foreach (AiProfile::where('user_id', $userId)->orderBy('id')->get() as $p) {
            if (isset($models[$p->provider]) && ($key = trim((string) $p->api_key)) !== '' && ($cfg = config('ai.providers.' . $p->provider))) {
                return new HostedEmbedder($p->provider, (string) $cfg['base_url'], $models[$p->provider], $key);
            }
        }
        // 2) the platform key from .env
        foreach ($models as $provider => $model) {
            $cfg = config('ai.providers.' . $provider);
            if ($cfg && !empty($cfg['key_env']) && ($key = trim((string) env($cfg['key_env']))) !== '') {
                return new HostedEmbedder($provider, (string) $cfg['base_url'], $model, $key);
            }
        }

        return new BasicEmbedder();
    }
}
