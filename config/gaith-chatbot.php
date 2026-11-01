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
     * Who is chatting, read from the host's signed-in user on the server. Product,
     * customer and person become the GAITH user id (qhse:7:42), so histories of
     * different customers or products never mix. Never point these at anything the
     * browser sends.
     *
     * customer / person sources:
     *   user:<path>   attribute of the signed-in user, dots follow relations
     *   config:<key>  config value the host sets server-side (e.g. its tenant middleware)
     *   value:<text>  fixed value, for a deployment with one customer
     * person left empty = the signed-in user's id.
     *
     *   QHSE: product qhse, customer user:employee.centralDBCompanyID,
     *         person user:employee.employeeSystemID (the same ids the QHSE MCP uses)
     *   ERP:  product erp, customer config:database.connections.mysql.database
     *         (the tenant database its tenant middleware selects), person empty
     */
    'identity' => [
        'product' => env('GAITH_IDENTITY_PRODUCT'),
        'customer' => env('GAITH_IDENTITY_CUSTOMER'),
        'person' => env('GAITH_IDENTITY_PERSON'),

        // A product whose ids cannot be described above names its own class here. Must
        // implement Osos\Gaith\Sdk\Laravel\Identity\ProductUserResolver. A class name,
        // not a closure, so `php artisan config:cache` keeps working.
        'resolver' => \Osos\Gaith\Sdk\Laravel\Identity\ConfiguredProductUserResolver::class,
    ],

    /*
     * Key for the identity proof: a short-lived RS256 JWT, signed here and sent to GAITH
     * with every chat request, saying which user (identity above) is chatting. GAITH
     * checks it with the public half registered on the chatbot's API key.
     *
     * One key pair per environment. The private key belongs in the host's secret store,
     * never in Git, email or chat. Give it either as PEM text (newlines may be written
     * as \n in .env) or as a path to a PEM file, not both.
     */
    'proof' => [
        // The id GAITH has this key's public half registered under, e.g. qhse-prod-1.
        'key_id' => env('GAITH_PROOF_KEY_ID'),
        'private_key' => env('GAITH_PROOF_PRIVATE_KEY'),
        'private_key_path' => env('GAITH_PROOF_PRIVATE_KEY_PATH'),
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

        // Widget colours per product, served to the browser by {prefix}/config.
        // Any CSS colour: #hex, rgb()/rgba(), hsl()/hsla() or a named colour.
        'theme' => [
            'primary_color' => env('GAITH_CHAT_WIDGET_PRIMARY_COLOR', '#1f6feb'),
            // Text/icons drawn on top of the primary colour (header, user bubbles, buttons).
            'text_color' => env('GAITH_CHAT_WIDGET_TEXT_COLOR', '#ffffff'),
        ],

        // The product's data protection policy, linked from the lock's hover note in the widget's header.
        // An https:// URL or a path on the host app (/privacy). Unset: the note shows without a link.
        'privacy_url' => env('GAITH_CHAT_WIDGET_PRIVACY_URL'),

        // Mic button for dictation. Speech is recognised by the user's browser and its vendor's
        // speech service (Google for Chrome, Microsoft for Edge, Apple for Safari), not by this
        // app or GAITH. Turn it off where that is not allowed by the product's data policy.
        'voice' => (bool) env('GAITH_CHAT_WIDGET_VOICE', true),

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
            // Extensions (or media types). The SDK supports pdf, doc, docx, xls, xlsx, csv, md,
            // txt, png, jpg, jpeg, webp, bmp, tif, tiff; enable only what GAITH accepts for chat
            // uploads (today: pdf, docx, png, jpg/jpeg), or users get files GAITH then refuses.
            'allowed_types' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('GAITH_CHAT_WIDGET_ATTACHMENT_TYPES', 'pdf,docx,png,jpg,jpeg'))
            ))),
        ],

        'max_message_length' => 8000,

        // Upper bound on history loaded after a page reload, so one very long
        // conversation cannot hold a PHP worker for many sequential GAITH calls.
        'history_max_messages' => 500,

        // How long {prefix}/status keeps GAITH's on/off answer per customer, in seconds, so
        // page loads do not each call GAITH. Also the longest a switch-off takes to show.
        // 0 asks GAITH on every page load. Failures are kept for at most 10 s.
        'status_cache_seconds' => (int) env('GAITH_CHAT_WIDGET_STATUS_CACHE_SECONDS', 60),

        // Each open reply holds one PHP worker. One user may have this many streaming at once;
        // more get 429 too_many_streams before anything reaches GAITH. Needs a cache store with
        // atomic add (redis, memcached, database, array), not the file store.
        'max_streams_per_user' => (int) env('GAITH_CHAT_WIDGET_MAX_STREAMS_PER_USER', 2),

        // Longest one reply may stream, in seconds (0 = no limit here). GAITH's own per-chatbot
        // limit ("Max seconds per reply", at most 600 today) still applies and is usually shorter.
        'max_reply_seconds' => (int) env('GAITH_CHAT_WIDGET_MAX_REPLY_SECONDS', 3600),
    ],
];
