# osos/gaith-sdk-php

PHP client SDK for the GAITH chatbot public HTTP/SSE API. Framework-agnostic core, with an optional
Laravel 8 bridge.

## Requirements

- PHP `^7.4`
- Laravel `^8.0` (only if using the service provider)

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

## Laravel usage

1. Publish the config: `php artisan vendor:publish --tag=gaith-chatbot-config`
2. Set env vars: `GAITH_CHATBOT_BASE_URL`, `GAITH_CHATBOT_ID`, `GAITH_CHATBOT_API_KEY`
3. Inject `Osos\Gaith\Sdk\GaithChatbotClient` (default connection), or resolve a named one:

```php
use Osos\Gaith\Sdk\Laravel\GaithChatbotClientFactory;

$client = app(GaithChatbotClientFactory::class)->get('hr'); // config('gaith-chatbot.connections.hr')
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
vendor/bin/phpunit
```
