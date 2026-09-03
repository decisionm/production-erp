<?php

return [
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

    'catalogue_path' => resource_path('schema-catalogue'),

    // How many ranked tables a question is answered from, before joins pull
    // in their neighbours.
    'tables_per_question' => 8,

    // Prior turns of the conversation replayed to the model.
    'history_turns' => 4,
];
