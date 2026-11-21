<?php
return [
    'meta' => ['app_id' => env('META_APP_ID'), 'app_secret' => env('META_APP_SECRET'), 'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'), 'graph_api_version' => env('META_GRAPH_API_VERSION')],
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'timeout' => (int) env('GROQ_TIMEOUT', 15),
    ],
    'google' => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'redirect_uri' => env('GOOGLE_REDIRECT_URI')],
];
