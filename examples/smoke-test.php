<?php

/**
 * Manual smoke test against a real GAITH backend. Not part of the automated test suite.
 *
 * Usage:
 *   1. composer install
 *   2. Copy .env.example to .env (repo root) and fill in real values.
 *   3. php examples/smoke-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
}

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Osos\Gaith\Sdk\Config\GaithChatbotConfig;
use Osos\Gaith\Sdk\Config\GaithUser;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Streaming\Adapters\GuzzleStreamingClient;
use Osos\Gaith\Sdk\Streaming\DoneEvent;
use Osos\Gaith\Sdk\Streaming\ErrorEvent;
use Osos\Gaith\Sdk\Streaming\SafetyBlockEvent;
use Osos\Gaith\Sdk\Streaming\TokenEvent;

function requiredEnv(string $name): string
{
    $value = $_ENV[$name] ?? getenv($name);
    if ($value === false || $value === null || $value === '') {
        fwrite(STDERR, "Missing required env var: {$name}\n");
        exit(1);
    }
    return $value;
}

$baseUrl = requiredEnv('GAITH_CHATBOT_BASE_URL');
$chatbotId = requiredEnv('GAITH_CHATBOT_ID');
$apiKey = requiredEnv('GAITH_CHATBOT_API_KEY');
$timeout = (int) (($_ENV['GAITH_CHATBOT_HTTP_TIMEOUT'] ?? getenv('GAITH_CHATBOT_HTTP_TIMEOUT')) ?: 30);

$config = new GaithChatbotConfig($baseUrl, $chatbotId, $apiKey, $timeout);

// LOCAL-DEV-ONLY: this machine's cURL reports a self-signed certificate in the chain for
// gaith-backend-dev.osos.om, almost always caused by local antivirus/VPN/proxy TLS inspection.
// 'verify' => false disables TLS certificate verification entirely. Scoped to *-dev hosts only
// so pointing this script at qa/uat/prod (which don't hit the same local TLS-inspection issue)
// still verifies certificates normally. NEVER acceptable in production or CI regardless of host;
// do not copy this pattern into real application code.
$isDevHost = strpos($baseUrl, '-dev.') !== false;
$guzzleOptions = $isDevHost ? ['verify' => false] : [];

$httpFactory = new HttpFactory();
$transport = new GaithHttpTransport($config, new Guzzle($guzzleOptions + ['timeout' => $timeout]), $httpFactory, $httpFactory);
$streamingClient = new GuzzleStreamingClient(new Guzzle($guzzleOptions + ['timeout' => 0]));

$client = new GaithChatbotClient($transport, $streamingClient);

echo "== Step 1: GET /meta ==\n";
$meta = $client->getMeta();
printf("name=%s status=%s greeting=%s\n\n", $meta->name(), $meta->status(), $meta->greeting() ?? '(none)');

echo "== Step 2: streamChat() ==\n";
$user = GaithUser::for('smoke-test-user');

foreach ($client->streamChat($user, 'Hello! This is a smoke test, please reply briefly.') as $event) {
    if ($event instanceof TokenEvent) {
        echo $event->content();
        continue;
    }
    if ($event instanceof DoneEvent) {
        echo "\n\n[done] finish_reason=" . ($event->finishReason() ?? '(none)')
            . " input_tokens=" . $event->usage()->inputTokens()
            . " output_tokens=" . $event->usage()->outputTokens() . "\n";
        break;
    }
    if ($event instanceof ErrorEvent) {
        echo "\n\n[error] code=" . $event->code() . " message=" . $event->message() . "\n";
        break;
    }
    if ($event instanceof SafetyBlockEvent) {
        echo "\n\n[safety_block]\n";
        break;
    }
}

echo "\nSmoke test complete.\n";
