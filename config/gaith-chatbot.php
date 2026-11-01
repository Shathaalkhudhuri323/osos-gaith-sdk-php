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
     * Who is chatting. The SDK reads this on the server from the signed-in user.
     *
     * product, customer and person are joined into the user id GAITH files chats under,
     * e.g. "qhse:7:42". Because the customer is part of it, chats of different customers
     * (or different products) never mix, even when two employees have the same number.
     *
     * customer and person say where to find the value:
     *   user:<field>   a field of the signed-in user; dots go into related records
     *   config:<key>   a config value the product sets on the server (e.g. in its tenant middleware)
     *   value:<text>   a fixed value, for an installation with only one customer
     * Leave person empty to use the signed-in user's normal id.
     *
     * Examples:
     *   QHSE: product "qhse", customer "user:employee.centralDBCompanyID",
     *         person "user:employee.employeeSystemID" (the same ids the QHSE MCP uses)
     *   ERP:  product "erp", customer "config:database.connections.mysql.database"
     *         (the customer's database, chosen by the ERP's tenant middleware), person empty
     *
     * Never point these at anything the browser sends.
     */
    'identity' => [
        'product' => env('GAITH_IDENTITY_PRODUCT'),
        'customer' => env('GAITH_IDENTITY_CUSTOMER'),
        'person' => env('GAITH_IDENTITY_PERSON'),

        // If a product's users cannot be described with the settings above, it can write its
        // own class (implementing Osos\Gaith\Sdk\Laravel\Identity\ProductUserResolver) and
        // name it here. It must be a class name, not a function, so `php artisan config:cache`
        // keeps working.
        'resolver' => \Osos\Gaith\Sdk\Laravel\Identity\ConfiguredProductUserResolver::class,
    ],

    /*
     * The key used to sign the identity proof: a small signed note sent to GAITH with every
     * request, saying which user (from the settings above) is chatting. GAITH checks the note
     * with the matching public key, which is saved on the chatbot's API key in GAITH.
     *
     * Use one key pair per environment (dev, prod...). Keep the private key in the server's
     * secret store, never in Git, email or chat. Give either the key text (line breaks can be
     * written as \n in .env) or the path of the key file, not both.
     */
    'proof' => [
        // The name GAITH knows this key by, e.g. qhse-prod-1.
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

        // Addresses a "Connect" link may point to. When a chatbot tool (an MCP server, such as
        // the QHSE MCP) needs the user to connect their own account, GAITH sends a link where
        // they sign in and approve. It is only shown if it starts with one of these addresses,
        // so a wrong or harmful link never reaches the user. Usually the MCP's public address.
        // Comma-separated in .env: https://qhse-mcp.example.com
        'connect_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GAITH_CHAT_WIDGET_CONNECT_ORIGINS', ''))
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

        // How many seconds to remember GAITH's "is the chatbot on for this customer?" answer,
        // so that not every page load has to ask GAITH. This is also the longest it takes for a
        // switch-off in GAITH to hide the chat box. 0 means ask GAITH on every page load.
        // A failed answer is remembered for at most 10 seconds.
        'status_cache_seconds' => (int) env('GAITH_CHAT_WIDGET_STATUS_CACHE_SECONDS', 60),

        // While a reply is being written it keeps one PHP worker busy. This is how many replies
        // one user may have running at the same time; another one gets "busy, try again"
        // (429 too_many_streams) without anything being sent to GAITH. Other users are not
        // affected. Use a cache store that supports this safely (redis, memcached or database),
        // not the file store.
        'max_streams_per_user' => (int) env('GAITH_CHAT_WIDGET_MAX_STREAMS_PER_USER', 2),

        // The longest one reply may take, in seconds (0 means no limit here). GAITH has its own
        // limit per chatbot ("Max seconds per reply", at most 600 today), which usually ends the
        // reply sooner.
        'max_reply_seconds' => (int) env('GAITH_CHAT_WIDGET_MAX_REPLY_SECONDS', 3600),
    ],
];
