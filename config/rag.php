<?php

/*
|--------------------------------------------------------------------------
| Business knowledge for the AI agents (RAG)
|--------------------------------------------------------------------------
|
| Texts the account "teaches" (FAQs, files, knowledge base articles) are cut into chunks, turned into vectors by an
| embedding model and searched by similarity when an AI agent answers a customer.
|
| Embeddings use a hosted model when the account has a key for one of the providers below (the key of one of its AI
| agents, or the platform key set in .env); otherwise a built-in "basic" word-based search keeps working with no key.
|
*/

return [
    // provider (see config/ai.php) => embedding model. Only OpenAI-compatible providers (/embeddings endpoint).
    'embedding_models' => [
        'openai' => env('RAG_OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'mistral' => env('RAG_MISTRAL_EMBEDDING_MODEL', 'mistral-embed'),
    ],

    // where vectors are kept: 'database' (default, no extra service) or 'qdrant' (dedicated vector database, see README)
    'vector_store' => env('RAG_VECTOR_STORE', 'database'),
    'qdrant' => [
        'url' => env('QDRANT_URL', 'http://qdrant:6333'),
        'api_key' => env('QDRANT_API_KEY'),
        'collection' => env('QDRANT_COLLECTION', 'sama_knowledge'),
    ],

    'chunk_chars' => (int) env('RAG_CHUNK_CHARS', 1500),
    'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 150),
    'top_k' => (int) env('RAG_TOP_K', 4),

    // minimum similarity (0..1) for a passage to count as an answer; below it the agent says it will check
    'min_score' => ['basic' => (float) env('RAG_MIN_SCORE_BASIC', 0.12), 'hosted' => (float) env('RAG_MIN_SCORE_HOSTED', 0.25)],

    // limits per account (a hosted multi-user install should keep them modest)
    'max_chunks' => (int) env('RAG_MAX_CHUNKS', 5000),
    'max_upload_kb' => (int) env('RAG_MAX_UPLOAD_KB', 10240),
    'crawl_max_pages' => (int) env('RAG_CRAWL_MAX_PAGES', 30),
    'max_source_chars' => (int) env('RAG_MAX_SOURCE_CHARS', 400000),
];
