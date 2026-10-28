<?php

/*
|--------------------------------------------------------------------------
| AI providers for the automatic WhatsApp replies
|--------------------------------------------------------------------------
|
| Every user picks a provider + model per agent (AI section). Three wire protocols cover all of them:
|   openai    - OpenAI-style /chat/completions (OpenAI, Groq, DeepSeek, Mistral, OpenRouter, Ollama, custom)
|   anthropic - Anthropic Messages API
|   gemini    - Google Gemini generateContent
|
| `key_env` is an OPTIONAL platform key: users who leave their own API key empty use it. Leave the env var unset to
| force every user to bring their own key. Model lists are only suggestions: any model id can be typed.
|
*/

return [

    // Hosts on private networks (localhost, 192.168.x, Docker...) can be used as "Ollama" / "custom" endpoints only when
    // this is true: a hosted multi-user install should keep it false so users cannot make the server call its own network.
    'allow_private_hosts' => (bool) env('AI_ALLOW_PRIVATE_HOSTS', env('APP_ENV', 'production') === 'local'),

    'timeout' => (int) env('AI_TIMEOUT', 40),

    // "Reply only during business hours": Monday to Friday
    'business_hours' => [
        'timezone' => env('AI_BUSINESS_TZ', 'America/Sao_Paulo'),
        'start' => (int) env('AI_BUSINESS_START', 9),
        'end' => (int) env('AI_BUSINESS_END', 19),
    ],

    'providers' => [
        'groq' => [
            'label' => 'Groq',
            'protocol' => 'openai',
            'base_url' => 'https://api.groq.com/openai/v1',
            'key_env' => 'GROQ_API_KEY',
            'max_tokens_param' => 'max_completion_tokens',
            'models' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'openai/gpt-oss-120b', 'openai/gpt-oss-20b'],
            'docs' => 'https://console.groq.com/keys',
        ],
        'openai' => [
            'label' => 'OpenAI (ChatGPT)',
            'protocol' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'key_env' => 'OPENAI_API_KEY',
            'max_tokens_param' => 'max_completion_tokens',
            'models' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1'],
            'docs' => 'https://platform.openai.com/api-keys',
        ],
        'anthropic' => [
            'label' => 'Anthropic (Claude)',
            'protocol' => 'anthropic',
            'base_url' => 'https://api.anthropic.com/v1',
            'key_env' => 'ANTHROPIC_API_KEY',
            'models' => ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'],
            'docs' => 'https://console.anthropic.com/settings/keys',
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'protocol' => 'gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'key_env' => 'GEMINI_API_KEY',
            'models' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash'],
            'docs' => 'https://aistudio.google.com/apikey',
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'protocol' => 'openai',
            'base_url' => 'https://api.deepseek.com/v1',
            'key_env' => 'DEEPSEEK_API_KEY',
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
            'docs' => 'https://platform.deepseek.com/api_keys',
        ],
        'mistral' => [
            'label' => 'Mistral',
            'protocol' => 'openai',
            'base_url' => 'https://api.mistral.ai/v1',
            'key_env' => 'MISTRAL_API_KEY',
            'models' => ['mistral-small-latest', 'mistral-medium-latest', 'mistral-large-latest'],
            'docs' => 'https://console.mistral.ai/api-keys',
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'protocol' => 'openai',
            'base_url' => 'https://openrouter.ai/api/v1',
            'key_env' => 'OPENROUTER_API_KEY',
            'models' => ['openai/gpt-4o-mini', 'anthropic/claude-3.5-haiku', 'meta-llama/llama-3.3-70b-instruct', 'google/gemini-2.0-flash-001'],
            'docs' => 'https://openrouter.ai/keys',
        ],
        'ollama' => [
            'label' => 'Ollama (local / self-hosted)',
            'protocol' => 'openai',
            'base_url' => 'http://localhost:11434/v1',
            'editable_url' => true,
            'needs_key' => false,
            'models' => ['llama3.2', 'llama3.1', 'qwen2.5', 'mistral', 'gemma2'],
            'docs' => 'https://ollama.com/library',
        ],
        'custom' => [
            'label' => 'Custom (OpenAI-compatible)',
            'protocol' => 'openai',
            'base_url' => '',
            'editable_url' => true,
            'url_required' => true,
            'models' => [],
            'docs' => null,
        ],
    ],
];
