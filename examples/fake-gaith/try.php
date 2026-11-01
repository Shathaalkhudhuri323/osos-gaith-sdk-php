<?php

/**
 * A quick check from the command line. It talks to a running fake GAITH the same way a
 * product would: it signs a proof for each request, asks whether the chatbot is on for
 * this customer, and then sends one message and prints the reply as it arrives.
 *
 *   php examples/fake-gaith/try.php
 *
 * Settings, from the environment (defaults match the fake GAITH's):
 *   FAKE_GAITH_URL          http://localhost:8787
 *   FAKE_GAITH_CHATBOT_ID   11111111-1111-1111-1111-111111111111
 *   FAKE_GAITH_API_KEY      fake-gaith-key
 *   GAITH_PROOF_PRIVATE_KEY_PATH, GAITH_PROOF_KEY_ID   the host's proof key
 *   TRY_PRODUCT, TRY_CUSTOMER, TRY_PERSON              who to be (qhse / 7 / 42)
 */

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Osos\Gaith\Sdk\Config\GaithChatbotConfig;
use Osos\Gaith\Sdk\Config\GaithUser;
use Osos\Gaith\Sdk\Exceptions\GaithApiException;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Identity\ProductUser;
use Osos\Gaith\Sdk\Identity\ProofSigner;
use Osos\Gaith\Sdk\Streaming\Adapters\GuzzleStreamingClient;
use Osos\Gaith\Sdk\Streaming\DoneEvent;
use Osos\Gaith\Sdk\Streaming\ErrorEvent;
use Osos\Gaith\Sdk\Streaming\TokenEvent;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

$keyPath = $env('GAITH_PROOF_PRIVATE_KEY_PATH');
if ($keyPath === '' || !is_readable($keyPath)) {
    fwrite(STDERR, "Set GAITH_PROOF_PRIVATE_KEY_PATH to the private key whose public half the fake GAITH uses.\n");
    exit(2);
}

$config = new GaithChatbotConfig(
    $env('FAKE_GAITH_URL', 'http://localhost:8787'),
    $env('FAKE_GAITH_CHATBOT_ID', '11111111-1111-1111-1111-111111111111'),
    $env('FAKE_GAITH_API_KEY', 'fake-gaith-key'),
    30
);
$factory = new HttpFactory();
$client = new GaithChatbotClient(
    new GaithHttpTransport($config, new Guzzle(['timeout' => 30, 'http_errors' => false]), $factory, $factory),
    new GuzzleStreamingClient(new Guzzle(['timeout' => 0]))
);

$signer = new ProofSigner((string) file_get_contents($keyPath), $env('GAITH_PROOF_KEY_ID', 'local-1'));
$who = ProductUser::create($env('TRY_PRODUCT', 'qhse'), $env('TRY_CUSTOMER', '7'), $env('TRY_PERSON', '42'));
$trace = sprintf('try-%s', bin2hex(random_bytes(4)));
$user = GaithUser::for($who->externalUserId())
    ->withTraceId($trace)
    ->withProof(function (string $chatbotId) use ($signer, $who): string {
        return $signer->sign($who, $chatbotId);
    });

echo "As {$who->externalUserId()}, trace {$trace}\n";

try {
    $on = $client->isEnabledFor($user);
    echo 'Chatbot on for this customer: ' . ($on ? 'yes' : 'no') . "\n";
    if (!$on) {
        exit(0); // the chat box would stay hidden here, so we stop too
    }
    echo "\n";

    foreach ($client->streamChat($user, 'Hello from try.php') as $event) {
        if ($event instanceof TokenEvent) {
            echo $event->content();
        } elseif ($event instanceof ErrorEvent) {
            echo "\n[error] " . json_encode($event) . "\n";
        } elseif ($event instanceof DoneEvent) {
            echo "\n\n[done]\n";
        }
    }
} catch (GaithApiException $e) {
    fwrite(STDERR, sprintf("Refused: HTTP %d %s - %s\n", $e->statusCode(), (string) $e->serverCode(), $e->getMessage()));
    exit(1);
}
