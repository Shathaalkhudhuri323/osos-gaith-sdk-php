<?php

/**
 * Runnable demo of the <gaith-chat> widget against this SDK, no Laravel needed.
 *
 *   php -S localhost:8000 examples/widget-demo/server.php
 *
 * With GAITH_CHATBOT_BASE_URL, GAITH_CHATBOT_ID and GAITH_CHATBOT_API_KEY set (env or
 * a .env file at the repo root) it talks to the real chatbot as "demo-user".
 * Without them it serves a scripted fake stream, so the frontend can be worked on
 * offline. Implements the same wire contract as the Laravel bridge
 * (src/Laravel/Http/GaithChatController.php) - keep the two in step.
 *
 * Demo only: there is no authentication here, every visitor is "demo-user".
 */

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Osos\Gaith\Sdk\Config\GaithChatbotConfig;
use Osos\Gaith\Sdk\Config\GaithUser;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Models\MessagePageOptions;
use Osos\Gaith\Sdk\Streaming\DoneEvent;
use Osos\Gaith\Sdk\Streaming\ErrorEvent;
use Osos\Gaith\Sdk\Streaming\FileEvent;
use Osos\Gaith\Sdk\Streaming\HeartbeatEvent;
use Osos\Gaith\Sdk\Streaming\MetaEvent;
use Osos\Gaith\Sdk\Streaming\SafetyBlockEvent;
use Osos\Gaith\Sdk\Streaming\TokenEvent;
use Osos\Gaith\Sdk\Streaming\Adapters\GuzzleStreamingClient;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

if (is_file($root . '/.env') && class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

if ($path === '/demo/photo-1.svg') {
    // A stand-in "incident photo" for fake mode, so inline images can be previewed offline.
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="300" viewBox="0 0 480 300">'
        . '<rect width="480" height="300" fill="#cfe3f7"/><rect y="200" width="480" height="100" fill="#8fb27a"/>'
        . '<circle cx="390" cy="70" r="34" fill="#f5c542"/>'
        . '<rect x="110" y="120" width="170" height="100" fill="#d9734e"/><polygon points="100,124 195,60 290,124" fill="#8c3b2a"/>'
        . '<rect x="175" y="165" width="40" height="55" fill="#5b3a29"/>'
        . '<text x="16" y="286" font-family="sans-serif" font-size="16" fill="#1d3b1d">Incident #42 - site photo (demo)</text></svg>';
    return true;
}

if ($path === '/gaith-chat.js') {
    header('Content-Type: application/javascript');
    header('Cache-Control: no-cache'); // always the freshly built widget while developing
    readfile($root . '/dist/gaith-chat.js');
    return true;
}

$env = static function (string $key): string {
    $value = $_ENV[$key] ?? getenv($key);
    return is_string($value) ? $value : '';
};

$client = null;
if ($env('GAITH_CHATBOT_BASE_URL') !== '' && $env('GAITH_CHATBOT_ID') !== '' && $env('GAITH_CHATBOT_API_KEY') !== '') {
    $config = new GaithChatbotConfig($env('GAITH_CHATBOT_BASE_URL'), $env('GAITH_CHATBOT_ID'), $env('GAITH_CHATBOT_API_KEY'), 30);
    $factory = new HttpFactory();
    $client = new GaithChatbotClient(
        new GaithHttpTransport($config, new Guzzle(['timeout' => 30]), $factory, $factory),
        new GuzzleStreamingClient(new Guzzle(['timeout' => 0]))
    );
}
$user = GaithUser::for('demo-user');

$maxUpload = 20 * 1024 * 1024;
$allowedUploads = ['application/pdf', 'image/png', 'image/jpeg', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

$filePayload = static function (\Osos\Gaith\Sdk\Models\FileRef $file): array {
    return ['artifact_id' => $file->artifactId(), 'filename' => $file->filename(), 'media_type' => $file->mediaType(), 'size_bytes' => $file->sizeBytes()];
};

$emit = static function (?string $event, array $data = []): void {
    echo $event === null ? ": heartbeat\n\n" : 'event: ' . $event . "\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
};

if ($method === 'GET' && $path === '/api/gaith/config') {
    header('Content-Type: application/json');
    echo json_encode([
        'theme' => array_filter([
            'primary_color' => $env('GAITH_CHAT_WIDGET_PRIMARY_COLOR') ?: '#1f6feb',
            'text_color' => $env('GAITH_CHAT_WIDGET_TEXT_COLOR') ?: '#ffffff',
        ]),
        'image_origins' => array_values(array_filter(array_map('trim', explode(',', $env('GAITH_CHAT_WIDGET_IMAGE_ORIGINS'))))),
        'attachments' => ['enabled' => true, 'max_files' => 8, 'max_bytes' => $maxUpload, 'allowed_types' => $allowedUploads],
    ]);
    return true;
}

if ($method === 'POST' && $path === '/api/gaith/attachments') {
    // Same checks as GaithChatController::upload(). Demo only: every visitor is
    // "demo-user", so the per-user ownership record the bridge keeps is skipped here.
    header('Content-Type: application/json');
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $tooBig = in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        http_response_code($tooBig ? 413 : 422);
        echo json_encode(['code' => $tooBig ? 'file_too_large' : 'file_missing']);
        return true;
    }
    if ($file['size'] > $maxUpload) {
        http_response_code(413);
        echo json_encode(['code' => 'file_too_large']);
        return true;
    }
    $type = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']));
    $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    if ($type === 'application/zip' && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'docx') {
        $type = $docx;
    }
    if (!in_array($type, $allowedUploads, true)) {
        http_response_code(422);
        echo json_encode(['code' => 'file_type_not_allowed']);
        return true;
    }
    if ($client === null) {
        http_response_code(201);
        echo json_encode(['artifact_id' => 'fake-' . bin2hex(random_bytes(4)), 'filename' => $file['name'], 'media_type' => $type, 'size_bytes' => $file['size']]);
        return true;
    }
    try {
        $upload = $client->uploadAttachment($user, $file['tmp_name'], $file['name'], $type);
    } catch (\Osos\Gaith\Sdk\Exceptions\GaithValidationException $e) {
        error_log('demo upload rejected by GAITH: ' . $e->getMessage());
        http_response_code(422);
        echo json_encode(['code' => 'file_rejected']);
        return true;
    } catch (\Osos\Gaith\Sdk\Exceptions\GaithApiException $e) {
        error_log('demo upload failed: ' . $e->getMessage());
        http_response_code($e->statusCode() === 413 ? 413 : 502);
        echo json_encode(['code' => $e->statusCode() === 413 ? 'file_too_large' : 'gaith_error']);
        return true;
    }
    http_response_code(201);
    echo json_encode([
        'artifact_id' => $upload->artifactId(),
        'filename' => $upload->filename(),
        'media_type' => $upload->mediaType() ?? $type,
        'size_bytes' => $upload->sizeBytes(),
    ]);
    return true;
}

if ($method === 'POST' && $path === '/api/gaith/stream') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') {
        http_response_code(422);
        return true;
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    @set_time_limit(0);

    if ($client === null) {
        $conversationId = $body['conversation_id'] ?? ('fake-' . bin2hex(random_bytes(4)));
        $emit('meta', ['conversation_id' => $conversationId]);
        $reply = "**Fake mode** (no GAITH credentials set). You said:\n\n> " . $message . "\n\n- markdown renders\n- tokens stream";
        if (preg_match('/leave|month|إجازة/iu', $message)) {
            // What a chatbot with follow-up questions enabled sends (GAITH present_options).
            foreach (preg_split('/(?<= )/u', 'Which month do you want to see your leave for?') as $piece) {
                $emit('token', ['content' => $piece]);
                usleep(40000);
            }
            $emit('question', ['question' => 'Which month do you want to see your leave for?', 'options' => ['October', 'November', 'December']]);
            $emit('done', ['message_id' => 'fake-q', 'conversation_id' => $conversationId, 'finish_reason' => 'question']);
            return true;
        }
        if (preg_match('/photo|image|picture|صور/iu', $message)) {
            // What a reply carrying an MCP image link (e.g. QHSE open_files) would look like.
            $reply = "Here is the latest photo attached to **incident #42**:\n\n"
                . "![Incident 42 - site photo](/demo/photo-1.svg)\n\n"
                . "Taken on site A. Want me to attach more photos?";
        }
        foreach (preg_split('/(?<= )/u', $reply) as $i => $piece) {
            if ($i === 3) {
                $emit(null); // a heartbeat mid-reply, as GAITH sends during long tool calls
            }
            $emit('token', ['content' => $piece]);
            usleep(40000);
        }
        $emit('done', ['message_id' => 'fake-m', 'conversation_id' => $conversationId, 'finish_reason' => 'stop']);
        return true;
    }

    $options = !empty($body['conversation_id']) ? ['conversation_id' => (string) $body['conversation_id']] : [];
    if (!empty($body['attachments']) && is_array($body['attachments'])) {
        $options['attachments'] = array_values(array_slice(array_filter($body['attachments'], 'is_string'), 0, 8));
    }
    try {
        foreach ($client->streamChat($user, $message, $options) as $event) {
            if ($event instanceof HeartbeatEvent) {
                $emit(null);
            } elseif ($event instanceof MetaEvent) {
                $emit('meta', ['conversation_id' => $event->conversationId()]);
            } elseif ($event instanceof TokenEvent) {
                $emit('token', ['content' => $event->content()]);
            } elseif ($event instanceof DoneEvent) {
                $emit('done', ['message_id' => $event->messageId(), 'conversation_id' => $event->conversationId(), 'finish_reason' => $event->finishReason()]);
            } elseif ($event instanceof ErrorEvent) {
                $emit('error', ['code' => $event->code(), 'message' => $event->message()]);
            } elseif ($event instanceof SafetyBlockEvent) {
                $emit('safety_block');
            } elseif ($event instanceof \Osos\Gaith\Sdk\Streaming\QuestionEvent) {
                $emit('question', ['question' => $event->question()->question(), 'options' => $event->question()->options()]);
            } elseif ($event instanceof FileEvent) {
                // No download_url: it needs the API key. The widget uses /api/gaith/files/{id}.
                $emit('file', $filePayload($event->file()));
            }
        }
    } catch (\Throwable $e) {
        error_log('demo stream failed: ' . $e->getMessage());
        $emit('error', ['code' => 'gaith_error', 'message' => 'The assistant could not complete this reply.']);
    }
    return true;
}

if ($method === 'GET' && preg_match('#^/api/gaith/conversations/([^/]+)/messages$#', $path, $m)) {
    header('Content-Type: application/json');
    $conversationId = rawurldecode($m[1]);

    if ($client === null) {
        echo json_encode(['messages' => [
            ['role' => 'user', 'content' => '(restored) earlier question', 'created_at' => gmdate('c', time() - 60)],
            ['role' => 'assistant', 'content' => '(restored) earlier **answer**', 'created_at' => gmdate('c', time() - 55)],
        ]]);
        return true;
    }

    try {
        $messages = [];
        foreach ($client->getMessages($user, $conversationId, new MessagePageOptions(null, 100)) as $message) {
            $files = [];
            if ($message->metadata() !== null) {
                foreach (array_merge($message->metadata()->attachments(), $message->metadata()->files()) as $ref) {
                    $files[] = $filePayload($ref);
                }
            }
            $question = $message->metadata() !== null ? $message->metadata()->question() : null;
            $messages[] = [
                'role' => $message->role(),
                'content' => $message->content(),
                'created_at' => $message->createdAt(),
                'files' => $files,
                'options' => $question !== null ? $question->options() : [],
            ];
        }
        echo json_encode(['messages' => $messages]);
    } catch (\Osos\Gaith\Sdk\Exceptions\GaithNotFoundException $e) {
        http_response_code(404);
        echo json_encode(['code' => 'conversation_not_found']);
    }
    return true;
}

if ($method === 'GET' && preg_match('#^/api/gaith/files/([0-9a-fA-F-]{8,64})$#', $path, $m)) {
    if ($client === null) {
        http_response_code(404);
        return true;
    }
    try {
        $file = $client->downloadAttachmentFile($user, $m[1]);
    } catch (\Osos\Gaith\Sdk\Exceptions\GaithGoneException $e) {
        http_response_code(410);
        return true;
    } catch (\Osos\Gaith\Sdk\Exceptions\GaithApiException $e) {
        http_response_code($e->statusCode() === 404 || $e->statusCode() === 403 ? 404 : 502);
        return true;
    }
    // Same safety headers as GaithChatController::file().
    $type = $file->mediaType() ?? 'application/octet-stream';
    $inline = in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'], true) && empty($_GET['download']);
    $name = str_replace(['"', "\r", "\n"], '', $file->filename() ?? 'file');
    header('Content-Type: ' . $type);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'" . ($type === 'application/pdf' ? '' : '; sandbox'));
    echo (string) $file->stream();
    return true;
}

http_response_code(404);
return true;
