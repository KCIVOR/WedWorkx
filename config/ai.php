<?php

return [
    // Master switch. false = scripted questions only and no call to any AI provider, ever.
    'enabled' => filter_var(env('AI_WORDING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Which provider class to use. "anthropic" is built in; the service only knows the WordingProvider interface.
    'provider' => env('AI_PROVIDER') ?: 'anthropic',
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL') ?: 'claude-haiku-5-5', // a small, fast model

    // Safety and cost controls.
    'max_tokens' => (int) (env('AI_MAX_TOKENS') ?: 150),               // hard cap on the reply length, per call
    'max_calls_per_session' => (int) (env('AI_MAX_CALLS') ?: 12),      // per chat session (visitor + chat)
    'timeout' => (int) (env('AI_TIMEOUT') ?: 4),                       // seconds; slower means the scripted question is used

    'anthropic' => [
        'base_url' => rtrim(env('AI_ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com', '/'),
        'version' => '2023-06-01',
    ],
];
