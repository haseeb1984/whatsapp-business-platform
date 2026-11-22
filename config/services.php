<?php
return [
    'meta' => ['app_id' => env('META_APP_ID'), 'app_secret' => env('META_APP_SECRET'), 'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'), 'graph_api_version' => env('META_GRAPH_API_VERSION'), 'appointment_reminder_template' => env('META_APPOINTMENT_REMINDER_TEMPLATE', 'appointment_reminder'), 'appointment_reminder_language' => env('META_APPOINTMENT_REMINDER_LANGUAGE', 'en_US')],
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'timeout' => (int) env('GROQ_TIMEOUT', 15),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'authorization_url' => env('GOOGLE_AUTHORIZATION_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_url' => env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'revoke_url' => env('GOOGLE_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
        'api_base_url' => env('GOOGLE_CALENDAR_API_BASE_URL', 'https://www.googleapis.com/calendar/v3'),
        'userinfo_url' => env('GOOGLE_USERINFO_URL', 'https://www.googleapis.com/oauth2/v2/userinfo'),
    ],
];
