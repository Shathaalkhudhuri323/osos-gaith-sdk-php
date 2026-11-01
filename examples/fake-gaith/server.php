<?php

/**
 * A pretend GAITH, so the SDK can be tried from start to finish before the real GAITH
 * accepts identity proofs. For local testing only, never for production.
 *
 * Start it with:
 *   FAKE_GAITH_PUBLIC_KEY_PATH=gaith-proof.pub.pem php -S localhost:8787 examples/fake-gaith/server.php
 * Then set the product's GAITH_CHATBOT_BASE_URL to http://localhost:8787 (README.md in this
 * folder has all the steps).
 *
 * For every request it does what real GAITH will have to do:
 *   - checks the API key and the identity proof
 *   - takes the user from the proof, and refuses a request that names a different user
 *   - answers "is the chatbot on?" from a list of switched-on customers
 * Its replies simply repeat who you are, so a wrong identity is easy to spot.
 * Every request is printed in the console with its trace id.
 */

require_once __DIR__ . '/FakeGaithProofVerifier.php';

$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

$chatbotId = $env('FAKE_GAITH_CHATBOT_ID', '11111111-1111-1111-1111-111111111111');
$apiKey = $env('FAKE_GAITH_API_KEY', 'fake-gaith-key');
$issuer = $env('FAKE_GAITH_ISSUER', 'qhse');
$enabled = array_values(array_filter(array_map('trim', explode(',', $env('FAKE_GAITH_ENABLED_CUSTOMERS')))));
$publicKeyPath = $env('FAKE_GAITH_PUBLIC_KEY_PATH');
// When set, every chat answer asks the user to connect their account first, using this link.
$connectUrl = $env('FAKE_GAITH_CONNECT_URL');
$connectServer = $env('FAKE_GAITH_CONNECT_SERVER', 'QHSE');

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$trace = $_SERVER['HTTP_X_TRACE_ID'] ?? '-';

$log = static function (string $outcome, string $user = '-') use ($method, $path, $trace): void {
    // Printed in the `php -S` console. The proof and the API key are never printed.
    error_log(sprintf('[fake-gaith] %s %s trace=%s user=%s %s', $method, $path, $trace, $user, $outcome));
};

$json = static function (int $status, $body) use ($log): bool {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    return true;
};

$refuse = static function (int $status, string $code, string $message) use ($json, $log): bool {
    $log("refused {$status} {$code}: {$message}");
    return $json($status, ['detail' => ['code' => $code, 'message' => $message]]);
};

if ($publicKeyPath === '' || !is_readable($publicKeyPath)) {
    return $refuse(500, 'not_configured', 'set FAKE_GAITH_PUBLIC_KEY_PATH to the public key of the host\'s proof key pair');
}

if (!preg_match('#^/api/v1/chatbots/([^/]+)/(.+)$#', $path, $m)) {
    return $refuse(404, 'not_found', 'not a chatbot API path');
}
[, $pathChatbot, $route] = $m;
if ($pathChatbot !== $chatbotId) {
    return $refuse(404, 'not_found', 'unknown chatbot');
}
if (!hash_equals($apiKey, (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''))) {
    return $refuse(401, 'invalid_api_key', 'wrong or missing X-API-Key');
}

$verifier = new FakeGaithProofVerifier(
    (string) file_get_contents($publicKeyPath),
    $issuer,
    $chatbotId,
    sys_get_temp_dir() . '/fake-gaith-seen-proofs.json'
);
try {
    $claims = $verifier->verify($_SERVER['HTTP_X_GAITH_IDENTITY_PROOF'] ?? null, time());
} catch (\RuntimeException $e) {
    return $refuse(401, 'invalid_proof', $e->getMessage());
}

// The user is whoever the proof says, never whoever the request body says (plan step 3.3).
$user = $claims['iss'] . ':' . $claims['tenant'] . ':' . $claims['sub'];
$isOn = $enabled === [] || in_array($claims['tenant'], $enabled, true);

if ($method === 'GET' && $route === 'access') {
    $log('access ' . ($isOn ? 'on' : 'off'), $user);
    return $json(200, ['enabled' => $isOn]);
}

if (!$isOn) {
    return $refuse(403, 'customer_not_enabled', "customer {$claims['tenant']} is switched off for this chatbot");
}

$claimed = $_GET['external_user_id'] ?? null;
if ($method === 'POST' && $route === 'chat') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $claimed = $body['external_user_id'] ?? null;
}
if ($claimed !== null && $claimed !== $user) {
    return $refuse(403, 'user_mismatch', "the request names {$claimed}, the proof {$user}");
}

if ($method === 'POST' && $route === 'chat') {
    $log('chat', $user);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    $send = static function (string $event, array $data, ?int $id = null): void {
        echo ($id !== null ? "id: {$id}\n" : '') . "event: {$event}\ndata: " . json_encode($data) . "\n\n";
        @ob_flush();
        flush();
    };

    $conversation = $body['conversation_id'] ?? bin2hex(random_bytes(16));
    $send('meta', ['conversation_id' => $conversation, 'user_message_id' => bin2hex(random_bytes(8)), 'stream_id' => bin2hex(random_bytes(8))], 0);

    // Pretend a tool needs the user's own account first, as real GAITH will (plan step 4.3).
    if ($connectUrl !== '') {
        $send('token', ['content' => "To answer this I need access to your {$connectServer} account. Connect it, then try again."], 1);
        $send('connect_needed', ['server' => $connectServer, 'url' => $connectUrl]);
        $send('done', ['message_id' => bin2hex(random_bytes(8)), 'conversation_id' => $conversation, 'finish_reason' => 'stop', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]], 2);
        return true;
    }

    $reply = "Fake GAITH here. Your proof checked out: you are **{$claims['sub']}** at customer **{$claims['tenant']}** "
        . "of **{$claims['iss']}**. You said:\n\n> " . str_replace("\n", "\n> ", (string) ($body['message'] ?? ''));
    $seq = 1;
    foreach (preg_split('/(?<= )/', $reply) as $word) {
        $send('token', ['content' => $word], $seq++);
        usleep(30000);
    }
    $send('done', ['message_id' => bin2hex(random_bytes(8)), 'conversation_id' => $conversation, 'finish_reason' => 'stop', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]], $seq);
    return true;
}

if ($method === 'GET' && preg_match('#^conversations/[^/]+/messages$#', $route)) {
    $log('history (always empty)', $user);
    return $json(200, []); // the fake GAITH does not keep chat history
}

if ($method === 'POST' && $route === 'attachments') {
    $file = $_FILES['file'] ?? null;
    $log('upload ' . ($file['name'] ?? '?'), $user);
    return $json(201, [
        'artifact_id' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff)),
        'filename' => $file['name'] ?? 'file',
        'media_type' => $file['type'] ?? null,
        'size_bytes' => (int) ($file['size'] ?? 0),
        'expires_at' => null,
        'download_url' => '/fake',
    ]);
}

return $refuse(404, 'not_found', "the fake GAITH does not serve {$method} {$route}");
