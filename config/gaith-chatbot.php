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

    /*
     * Ready-made routes for the <gaith-chat> browser widget. Off by default: turning
     * them on exposes endpoints, so the host must first decide which auth middleware
     * guards them.
     */
    'widget' => [
        'enabled' => env('GAITH_CHAT_WIDGET_ENABLED', false),

        // Routes are registered as {prefix}/stream and {prefix}/conversations/{id}/messages.
        'prefix' => env('GAITH_CHAT_WIDGET_PREFIX', 'api/gaith'),

        // Must include the host's own authentication, e.g. ['api', 'auth:api'].
        'middleware' => ['api', 'auth'],

        // Connection under `connections` the widget talks to; null uses `default`.
        'connection' => env('GAITH_CHAT_WIDGET_CONNECTION'),

        // Turns the host's signed-in user into the GAITH external_user_id. Must
        // implement Osos\Gaith\Sdk\Laravel\Http\GaithUserResolver. A class name,
        // not a closure, so `php artisan config:cache` keeps working.
        'user_resolver' => \Osos\Gaith\Sdk\Laravel\Http\AuthGaithUserResolver::class,

        // Prepended to the host user id so ids from different apps sharing one
        // chatbot can never collide (e.g. 'erp-user-', 'qhse-user-').
        'user_prefix' => env('GAITH_CHAT_WIDGET_USER_PREFIX', 'user-'),

        // Widget colours per product, served to the browser by {prefix}/config.
        // Any CSS colour: #hex, rgb()/rgba(), hsl()/hsla() or a named colour.
        'theme' => [
            'primary_color' => env('GAITH_CHAT_WIDGET_PRIMARY_COLOR', '#1f6feb'),
            // Text/icons drawn on top of the primary colour (header, user bubbles, buttons).
            'text_color' => env('GAITH_CHAT_WIDGET_TEXT_COLOR', '#ffffff'),
        ],

        // Origins the widget may load markdown images from besides the host app itself,
        // e.g. an MCP server that hands out image links (QHSE: its public MCP URL).
        // Images from anywhere else render as plain links: a prompt-injected reply
        // could otherwise leak conversation data through an image URL.
        // Comma-separated in .env: https://qhse-mcp.example.com,https://cdn.example.com
        'image_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GAITH_CHAT_WIDGET_IMAGE_ORIGINS', ''))
        ))),

        // Files users attach to a message. Uploads go to GAITH (it stores them, scoped to the
        // user, for a limited time); the host only checks and forwards them. Keep these at or
        // below GAITH's own limits, and raise PHP's upload_max_filesize / post_max_size to match.
        'attachments' => [
            'enabled' => env('GAITH_CHAT_WIDGET_ATTACHMENTS', true),
            'max_files' => 8,
            'max_bytes' => 20 * 1024 * 1024,
            'allowed_types' => [
                'application/pdf',
                'image/png',
                'image/jpeg',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ],

        'max_message_length' => 8000,

        // Upper bound on history loaded after a page reload, so one very long
        // conversation cannot hold a PHP worker for many sequential GAITH calls.
        'history_max_messages' => 500,
    ],
];
