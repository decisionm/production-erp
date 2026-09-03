<?php

return [
    // Who turns a question into SQL:
    //   anthropic — Claude (the default, and what the design was reviewed against)
    //   openai    — a GPT model
    //   rules     — no model at all: a fixed rule set over the real tables,
    //               needing no key, no credit and no network. The only driver
    //               an empty credit balance cannot switch off.
    // The choice changes THAT STEP ONLY. The permission filter that decides
    // which tables exist for a reader, SqlGuard, the row cap and the answer
    // template are identical on all three paths.
    'driver' => env('ASK_ERP_DRIVER', 'anthropic'),

    // Anthropic API key. Read from env only; never logged, never sent to the SPA.
    'api_key' => env('ANTHROPIC_API_KEY'),

    // The model that writes SQL. Adaptive thinking is the model's default;
    // effort is the cost lever (low | medium | high).
    'model' => env('ASK_ERP_MODEL', 'claude-opus-5'),
    'effort' => env('ASK_ERP_EFFORT', 'medium'),
    'max_tokens' => 4000,
    'timeout' => 45,

    // Rows returned to the page. SqlGuard appends LIMIT row_limit when the
    // model wrote none, and QueryRunner truncates to it regardless.
    'row_limit' => 200,

    // Database connection the guarded SELECT runs on. Null means the default
    // connection. Live sets ASK_ERP_DB_CONNECTION=ask_erp with a read-only
    // MySQL user (config/database.php).
    'connection' => env('ASK_ERP_DB_CONNECTION'),

    // The OpenAI path, used only when driver = openai. Its own key, because
    // the two providers are separate accounts with separate billing and one
    // must never be tried against the other's endpoint.
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('ASK_ERP_OPENAI_MODEL', 'gpt-5.2'),
        // minimal | low | medium | high. Low by default: the schema is
        // already in the prompt, so this is transcription more than
        // reasoning, and latency is the binding constraint on shared hosting.
        'reasoning_effort' => env('ASK_ERP_OPENAI_EFFORT', 'low'),
        // Reasoning tokens are spent inside this ceiling, so it sits well
        // above the Anthropic max_tokens rather than level with it.
        'max_completion_tokens' => (int) env('ASK_ERP_OPENAI_MAX_TOKENS', 8000),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    'catalogue_path' => resource_path('schema-catalogue'),

    // How many ranked tables a question is answered from, before joins pull
    // in their neighbours.
    'tables_per_question' => 8,

    // Prior turns of the conversation replayed to the model.
    'history_turns' => 4,
];
