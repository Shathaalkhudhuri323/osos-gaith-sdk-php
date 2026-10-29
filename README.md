# osos/gaith-sdk-php

PHP client SDK for the GAITH chatbot public HTTP/SSE API. Framework-agnostic core, an optional
Laravel bridge, and a drop-in `<gaith-chat>` browser widget backed by ready-made Laravel routes.

## Requirements

- PHP `^7.4` (core); the Laravel bridge is tested on Laravel 10 and 12 (PHP 8.1+)
- Widget: any evergreen browser (no Internet Explorer); works in any frontend framework, including Angular 5

## Security

Authentication is a single, chatbot-scoped API key (`X-API-Key`), configured once per chatbot connection.
**This key must never be exposed to a browser or mobile client** — it is a server-side-only credential
with the `chatbot:chat` permission. `external_user_id` scopes chat history to an end user; it is not an
authentication mechanism.

## Installation

```bash
composer require osos/gaith-sdk-php
```

## Framework-agnostic usage

```php
use Osos\Gaith\Sdk\Config\GaithChatbotConfig;
use Osos\Gaith\Sdk\Config\GaithUser;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Streaming\Adapters\GuzzleStreamingClient;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;

$config = new GaithChatbotConfig(
    'https://gaith-backend-dev.osos.om',
    '11111111-1111-1111-1111-111111111111',
    'sk-your-chatbot-scoped-key',
    30
);

$factory = new HttpFactory();
$transport = new GaithHttpTransport($config, new Guzzle(['timeout' => 30]), $factory, $factory);
$streamingClient = new GuzzleStreamingClient(new Guzzle(['timeout' => 0]));

$client = new GaithChatbotClient($transport, $streamingClient);

$user = GaithUser::for('external-user-42');

foreach ($client->streamChat($user, 'Hello!') as $event) {
    if ($event instanceof \Osos\Gaith\Sdk\Streaming\TokenEvent) {
        echo $event->content();
    }
    if ($event->isTerminal()) {
        break;
    }
}
```

**Note on PSR-18**: any PSR-18 `ClientInterface` implementation works for the JSON transport
(`GaithHttpTransport`) — Guzzle `^7.0.1` implements it natively; Guzzle `^6.3.1` does not and needs an
adapter such as `php-http/guzzle6-adapter`. The streaming path (`StreamingHttpClientInterface`) is
SDK-owned, not PSR-18 — implement it yourself if you need a transport other than the bundled
`GuzzleStreamingClient`.

**Note on the Laravel bridge**: `composer.json` requires `guzzlehttp/guzzle: ^7.0.1` because the bundled
Laravel bridge (`Osos\Gaith\Sdk\Laravel\GaithChatbotClientFactory`) hardcodes `GuzzleHttp\Psr7\HttpFactory`
(only available in `guzzlehttp/psr7` ^2.x, shipped by Guzzle ^7.2+) and constructs `GuzzleHttp\Client`
directly as both the PSR-18 client and the streaming adapter's backing client — Guzzle 6 does not
implement PSR-18 and would fail at runtime, not at `composer install` time. If you must use Guzzle
`^6.3.1` with `php-http/guzzle6-adapter` as described above, you can only do so with the framework-agnostic
core (`GaithHttpTransport` + your own PSR-18 client); the Laravel bridge is not compatible with it.

## Laravel usage

1. Publish the config: `php artisan vendor:publish --tag=gaith-chatbot-config`
2. Set env vars: `GAITH_CHATBOT_BASE_URL`, `GAITH_CHATBOT_ID`, `GAITH_CHATBOT_API_KEY`
3. Inject `Osos\Gaith\Sdk\GaithChatbotClient` (default connection), or resolve a named one:

```php
use Osos\Gaith\Sdk\Laravel\GaithChatbotClientFactory;

$client = app(GaithChatbotClientFactory::class)->get('hr'); // config('gaith-chatbot.connections.hr')
```

## Chat widget (`<gaith-chat>`)

A full chat UI for any web app, in two halves that ship together in this package:

```
Browser: <gaith-chat>  ──fetch + SSE──►  Your Laravel app: widget routes  ──this SDK──►  GAITH
         (dist/gaith-chat.js)            (src/Laravel/Http/GaithChatController)
```

The browser only ever calls **your** backend. The API key, and the decision of who the user is, stay
on the server. See [ARCHITECTURE.md §11](docs/ARCHITECTURE.md) for the design.

### Backend (Laravel)

1. `composer require osos/gaith-sdk-php`, then set the `GAITH_CHATBOT_*` env vars (see `.env.example`).
2. `php artisan vendor:publish --tag=gaith-chatbot-config` and in `config/gaith-chatbot.php` set
   `widget.middleware` to **your app's auth stack**. Example for the ERP:
   `['api', 'auth.api.keycloak', 'request.signature']`.
3. Set `GAITH_CHAT_WIDGET_ENABLED=true`, `GAITH_CHAT_WIDGET_PREFIX=api/v1/gaith` and
   `GAITH_CHAT_WIDGET_USER_PREFIX=erp-user-` (a unique prefix per product).
4. `php artisan vendor:publish --tag=gaith-chat-assets` copies the widget to
   `public/vendor/gaith-chat/gaith-chat.js`. Re-run it after every SDK update so widget and routes match.

5. Optional: set this product's colours with `GAITH_CHAT_WIDGET_PRIMARY_COLOR` / `GAITH_CHAT_WIDGET_TEXT_COLOR`
   (default blue `#1f6feb` / white), or edit `widget.theme` in the published config.

6. Optional: `GAITH_CHAT_WIDGET_IMAGE_ORIGINS` lists extra origins that markdown images may load
   from, e.g. an MCP server that returns image links. Images from anywhere else render as plain links.
7. Users can attach files (PDF, PNG, JPEG, DOCX up to 20 MB, 8 per message by default; `widget.attachments`
   in the config, `GAITH_CHAT_WIDGET_ATTACHMENTS=false` to turn off). Raise PHP's `upload_max_filesize` and
   `post_max_size` to at least 20M (PHP defaults to 2M), and the web server's body limit (nginx
   `client_max_body_size`).

This registers five routes:

| Route | Purpose |
|---|---|
| `GET {prefix}/config` | Per-product settings (colours, allowed image origins, attachment limits) |
| `POST {prefix}/attachments` (multipart `file`) | Uploads one attached file to GAITH, returns its `artifact_id` |
| `POST {prefix}/stream` `{message, conversation_id?, attachments?}` | One chat turn, streamed back as SSE |
| `GET {prefix}/conversations/{id}/messages` | History, including each message's files |
| `GET {prefix}/files/{artifact_id}` | A file from the user's conversations (`?download=1` forces a save) |

**Attachments:** GAITH stores uploaded files (scoped to the user, deleted after 30 days); the host only
checks and forwards them, and stores nothing. The host remembers which uploads each user made (in its
cache) and refuses anything else in `attachments`, because GAITH resolves attachment ids per chatbot,
not per user. If your host signs requests, `getHeaders` also receives `form` (the `FormData`) for uploads.

**Files and images:** files a GAITH tool produces (a generated PDF, image…) arrive as `file` events.
Images (PNG/JPEG/GIF/WebP) show inline; PDFs open in a new tab; other types download. They come
back with the history (GAITH keeps them 30 days, then they show as expired). The browser never sees
GAITH's download URL: every file goes through `{prefix}/files/…` with the host's own auth.

The user is resolved server-side from `$request->user()`. To map users differently, bind your own
`Osos\Gaith\Sdk\Laravel\Http\GaithUserResolver` or set `widget.user_resolver`.

**Behind nginx:** responses already send `X-Accel-Buffering: no`. Make sure no other proxy buffers or
times out `text/event-stream` responses. Heartbeats are relayed every ~15s.

### Frontend (any framework)

```html
<!-- Add the SDK version so browsers fetch the new widget after each update. -->
<script src="https://your-backend/vendor/gaith-chat/gaith-chat.js?v=0.2.0"></script>

<gaith-chat endpoint="api/v1/gaith" lang="en" bot-name="GAITH"></gaith-chat>
```

```js
const chat = document.querySelector('gaith-chat');

// Required when your API needs auth headers. Called for every request with
// { method, path, body, payload } so you can sign it exactly as your app does.
chat.getHeaders = ({ method, path, body }) => ({
  Authorization: 'Bearer ' + localStorage.getItem('access_token'),
  'X-CSRF-TOKEN': signRequest(body, path, method.toLowerCase()),
});

chat.options = {
  welcomeMessage: { en: 'Hello! How can I help?', ar: 'مرحبا! كيف يمكنني مساعدتك؟' },
  errorMessage:   { en: 'Sorry, something went wrong.', ar: 'عذرًا، حدث خطأ.' },
  // Optional fixed starter buttons. Not needed for GAITH's own clickable options: when a
  // chatbot has follow-up questions enabled, its clarifying questions arrive with answer
  // buttons automatically (shown under the latest reply, and again after a reload).
  quickOptions:   { en: ['Show my pending approvals'], ar: ['إظهار الموافقات المعلقة'] },
  placeholder:    { en: 'Example: “Show my pending approvals”', ar: 'مثال: «اعرض الموافقات المعلقة»' },
};
```

| Attribute | Meaning |
|---|---|
| `endpoint` | The `widget.prefix` route, relative or absolute |
| `lang` | `en` or `ar` (RTL); falls back to `<html lang>` |
| `bot-name` | Header title |
| `launcher-icon` | Optional image URL for the floating button |

Methods: `open()`, `close()`, `reset()`, `send(text)`. Event: `gaith-chat:error` (`detail.code`).

**Colours** come from the backend `.env` (see above), so each product is branded without a frontend
change. Precedence: page CSS > backend `.env`/config > built-in blue. Page-level overrides and layout
use CSS custom properties: `gaith-chat { --gaith-chat-primary: #E33A33; --gaith-chat-bottom: 66px; }`
(also `--gaith-chat-on-primary`, `--gaith-chat-side`, `--gaith-chat-z-index`, `--gaith-chat-font`).

**Angular (including Angular 5):** add `schemas: [CUSTOM_ELEMENTS_SCHEMA]` to the module that uses the
tag. Set `getHeaders` once, e.g. in `AppComponent.ngAfterViewInit`. No npm install or Angular build
change is needed: the script is plain, prebuilt JavaScript.

### Try it without Laravel

```bash
php -S localhost:8000 examples/widget-demo/server.php
```

Without GAITH credentials it serves a scripted fake stream. With `GAITH_CHATBOT_*` set it talks to the
real chatbot.

### Working on the widget

```bash
cd widget && npm install
npm test            # vitest
npm run build       # writes ../dist/gaith-chat.js - commit it with the source change
```

## Error handling

All API errors throw a subclass of `Osos\Gaith\Sdk\Exceptions\GaithApiException`:
`GaithAuthException` (401), `GaithForbiddenException` (403), `GaithNotFoundException` (404),
`GaithGoneException` (410), `GaithValidationException` (422), `GaithRateLimitException` (429), or the
base `GaithApiException` for anything else. Each exposes `statusCode()`, `serverCode()`,
`responseBody()`.

## Testing

```bash
composer install
vendor/bin/phpunit          # SDK + Laravel bridge (orchestra/testbench)
cd widget && npm test       # widget
```
