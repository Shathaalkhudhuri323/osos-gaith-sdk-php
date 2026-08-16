<?php

return [
    'default' => env('GAITH_CHATBOT_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'base_url' => env('GAITH_CHATBOT_BASE_URL'),
            'chatbot_id' => env('GAITH_CHATBOT_ID'),
            'api_key' => env('GAITH_CHATBOT_API_KEY'),
            'http_timeout' => env('GAITH_CHATBOT_HTTP_TIMEOUT', 30),
        ],
    ],
];
