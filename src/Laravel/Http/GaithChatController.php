<?php

namespace Osos\Gaith\Sdk\Laravel\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Osos\Gaith\Sdk\Config\GaithUser;
use Osos\Gaith\Sdk\Exceptions\GaithApiException;
use Osos\Gaith\Sdk\Exceptions\GaithForbiddenException;
use Osos\Gaith\Sdk\Exceptions\GaithGoneException;
use Osos\Gaith\Sdk\Exceptions\GaithNotFoundException;
use Osos\Gaith\Sdk\Exceptions\GaithRateLimitException;
use Osos\Gaith\Sdk\Exceptions\GaithValidationException;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Laravel\GaithChatbotClientFactory;
use Osos\Gaith\Sdk\Models\FileRef;
use Osos\Gaith\Sdk\Models\MessagePageOptions;
use Osos\Gaith\Sdk\Streaming\ChatEvent;
use Osos\Gaith\Sdk\Streaming\DoneEvent;
use Osos\Gaith\Sdk\Streaming\ErrorEvent;
use Osos\Gaith\Sdk\Streaming\FileEvent;
use Osos\Gaith\Sdk\Streaming\HeartbeatEvent;
use Osos\Gaith\Sdk\Streaming\LimitEvent;
use Osos\Gaith\Sdk\Streaming\MetaEvent;
use Osos\Gaith\Sdk\Streaming\QuestionEvent;
use Osos\Gaith\Sdk\Streaming\SafetyBlockEvent;
use Osos\Gaith\Sdk\Streaming\StreamDroppedException;
use Osos\Gaith\Sdk\Streaming\TokenEvent;
use Osos\Gaith\Sdk\Streaming\ToolCallEvent;
use Osos\Gaith\Sdk\Streaming\ToolResultEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-side proxy between the <gaith-chat> widget and GAITH.
 *
 * The browser never talks to GAITH directly: the chatbot API key is scoped to the
 * whole chatbot, so it stays here and the widget only calls the host's own
 * authenticated routes. The wire format emitted here is the widget's contract -
 * add fields, never rename or remove them.
 */
final class GaithChatController extends Controller
{
    private const HISTORY_PAGE_SIZE = 100;

    /**
     * Types a browser may render in place. Everything else - notably HTML and SVG,
     * which could run script on the host app's own origin - is only ever downloaded.
     */
    private const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    /** @var Container */
    private $app;

    /** @var GaithUserResolver */
    private $users;

    public function __construct(Container $app, GaithUserResolver $users)
    {
        $this->app = $app;
        $this->users = $users;
    }

    /**
     * Per-product widget settings from the host's .env, so each app can brand the
     * widget without a frontend change. Invalid colours are dropped (the widget
     * then keeps its default) rather than passed into the browser's CSS.
     */
    public function config(): JsonResponse
    {
        $theme = [];
        foreach (['primary_color', 'text_color'] as $key) {
            $value = config('gaith-chatbot.widget.theme.' . $key);
            if (is_string($value) && self::isCssColor($value)) {
                $theme[$key] = trim($value);
            }
        }

        $origins = [];
        foreach ((array) config('gaith-chatbot.widget.image_origins', []) as $origin) {
            // Origins only (scheme://host[:port]); a path or anything else is dropped.
            if (is_string($origin) && preg_match('#^(https?://[a-z0-9.-]+(:\d{1,5})?)/?$#i', trim($origin), $m)) {
                $origins[] = strtolower($m[1]);
            }
        }

        return new JsonResponse([
            'theme' => (object) $theme,
            'image_origins' => array_values(array_unique($origins)),
            'attachments' => [
                'enabled' => self::attachmentsEnabled(),
                'max_files' => self::maxAttachments(),
                'max_bytes' => self::maxAttachmentBytes(),
                'allowed_types' => self::allowedAttachmentTypes(),
            ],
        ]);
    }

    /**
     * Uploads one file the user attached to their next message. GAITH stores it;
     * the widget then sends its artifact id with the message.
     */
    public function upload(Request $request): JsonResponse
    {
        if (!self::attachmentsEnabled()) {
            return new JsonResponse(['code' => 'attachments_disabled'], 404);
        }
        $user = $this->userOrAbort($request);

        $file = $request->file('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['code' => 'file_missing'], 422);
        }
        if (!$file->isValid()) {
            // Usually PHP's own upload_max_filesize / post_max_size refusing it.
            $tooBig = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            return new JsonResponse(['code' => $tooBig ? 'file_too_large' : 'file_missing'], $tooBig ? 413 : 422);
        }
        if ($file->getSize() > self::maxAttachmentBytes()) {
            return new JsonResponse(['code' => 'file_too_large'], 413);
        }

        $type = self::attachmentType($file);
        if ($type === null) {
            return new JsonResponse(['code' => 'file_type_not_allowed'], 422);
        }

        try {
            $upload = $this->client()->uploadAttachment($user, $file->getRealPath(), $file->getClientOriginalName(), $type);
        } catch (GaithValidationException $e) {
            return new JsonResponse(['code' => 'file_rejected'], 422);
        } catch (GaithRateLimitException $e) {
            return new JsonResponse(['code' => 'rate_limited'], 429);
        } catch (GaithApiException $e) {
            if ($e->statusCode() === 413) {
                return new JsonResponse(['code' => 'file_too_large'], 413);
            }
            $this->logFailure('upload_failed', $e);

            return new JsonResponse(['code' => 'gaith_error'], 502);
        }

        // GAITH resolves attachment ids per chatbot, not per end user, so it is this
        // record - not GAITH - that stops one user sending another user's upload.
        Cache::put(self::uploadKey($user, $upload->artifactId()), true, now()->addDay());

        return new JsonResponse([
            'artifact_id' => $upload->artifactId(),
            'filename' => $upload->filename(),
            'media_type' => $upload->mediaType() ?? $type,
            'size_bytes' => $upload->sizeBytes(),
        ], 201);
    }

    /**
     * The file's type from its contents (not the browser's claim), or null when it is
     * not an allowed type. Older libmagic reports DOCX as a ZIP; only then the
     * browser's DOCX type is taken, and GAITH checks the bytes again on receipt.
     */
    private static function attachmentType(UploadedFile $file): ?string
    {
        $allowed = self::allowedAttachmentTypes();
        $detected = strtolower((string) $file->getMimeType());
        if (in_array($detected, $allowed, true)) {
            return $detected;
        }

        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        if (in_array($detected, ['application/zip', 'application/octet-stream'], true)
            && in_array($docx, $allowed, true)
            && strtolower($file->getClientOriginalExtension()) === 'docx'
            && strtolower((string) $file->getClientMimeType()) === $docx
        ) {
            return $docx;
        }

        return null;
    }

    private static function uploadKey(GaithUser $user, string $artifactId): string
    {
        return 'gaith-chat:upload:' . sha1($user->id() . '|' . $artifactId);
    }

    private static function attachmentsEnabled(): bool
    {
        return (bool) filter_var(config('gaith-chatbot.widget.attachments.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    private static function maxAttachments(): int
    {
        return max(1, (int) config('gaith-chatbot.widget.attachments.max_files', 8));
    }

    private static function maxAttachmentBytes(): int
    {
        return max(1, (int) config('gaith-chatbot.widget.attachments.max_bytes', 20 * 1024 * 1024));
    }

    /** @return string[] */
    private static function allowedAttachmentTypes(): array
    {
        return array_values(array_map('strtolower', array_filter(
            (array) config('gaith-chatbot.widget.attachments.allowed_types', []),
            'is_string'
        )));
    }

    /**
     * Serves a file from this user's conversations (their uploads, or files a tool
     * produced), so the browser never needs the GAITH API key or URL.
     */
    public function file(Request $request, string $artifact): Response
    {
        $user = $this->userOrAbort($request);
        // Artifact ids are opaque GAITH values, not necessarily UUIDs. Reject only
        // values that could change the path segment or make an unreasonable request.
        if (strlen($artifact) > 255
            || $artifact === '.'
            || $artifact === '..'
            || preg_match('/[\/\\\\\x00-\x1F\x7F]/', $artifact)
        ) {
            return new JsonResponse(['code' => 'file_not_found'], 404);
        }

        try {
            $file = $this->client()->downloadAttachmentFile($user, $artifact);
        } catch (GaithNotFoundException | GaithForbiddenException $e) {
            // GAITH scopes files to this user's conversations; anyone else's is a 404.
            return new JsonResponse(['code' => 'file_not_found'], 404);
        } catch (GaithGoneException $e) {
            return new JsonResponse(['code' => 'file_expired'], 410);
        } catch (GaithApiException $e) {
            $this->logFailure('file_failed', $e);

            return new JsonResponse(['code' => 'gaith_error'], 502);
        }

        $type = $file->mediaType() ?? 'application/octet-stream';
        $inline = in_array($type, self::INLINE_TYPES, true) && !$request->boolean('download');
        $stream = $file->stream();

        $response = new StreamedResponse(function () use ($stream) {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (!$stream->eof()) {
                echo $stream->read(65536);
                $this->flushOutput();
            }
        });

        $response->headers->set('Content-Type', $type);
        $response->headers->set(
            'Content-Disposition',
            self::disposition($inline ? 'inline' : 'attachment', $file->filename() ?? 'file')
        );
        if ($file->sizeBytes() !== null) {
            $response->headers->set('Content-Length', (string) $file->sizeBytes());
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Opened directly, a file must not act as a page of the host app. Chrome
        // refuses to show a PDF in a sandboxed document, so PDFs skip only that part.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'" . ($type === 'application/pdf' ? '' : '; sandbox')
        );
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }

    private static function disposition(string $kind, string $name): string
    {
        $name = str_replace(["\r", "\n", '"', '\\', '/'], '', $name);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $kind, $ascii, rawurlencode($name));
    }

    private static function isCssColor(string $value): bool
    {
        return (bool) preg_match(
            '/^\s*(#[0-9a-fA-F]{3,8}|(rgb|hsl)a?\(\s*[0-9.,%\s\/deg]+\)|[a-zA-Z]{3,30})\s*$/',
            $value
        );
    }

    /**
     * Relays one chat turn to GAITH and re-emits its events to the browser as SSE.
     */
    public function stream(Request $request): Response
    {
        $validated = $request->validate([
            'message' => 'required|string|max:' . (int) config('gaith-chatbot.widget.max_message_length', 8000),
            'conversation_id' => 'nullable|string|max:255',
            'attachments' => 'nullable|array|max:' . self::maxAttachments(),
            'attachments.*' => 'string|max:255',
        ]);

        $user = $this->userOrAbort($request);

        $options = [];
        if (!empty($validated['conversation_id'])) {
            $options['conversation_id'] = $validated['conversation_id'];
        }

        $attachments = array_values(array_unique($validated['attachments'] ?? []));
        if ($attachments !== []) {
            if (!self::attachmentsEnabled()) {
                return new JsonResponse(['code' => 'attachments_disabled'], 422);
            }
            foreach ($attachments as $artifactId) {
                if (!Cache::has(self::uploadKey($user, $artifactId))) {
                    // Not uploaded by this user through this app (or long ago): refused
                    // rather than forwarded, so ids cannot be borrowed across users.
                    return new JsonResponse(['code' => 'attachment_not_found'], 422);
                }
            }
            $options['attachments'] = $attachments;
        }

        $client = $this->client();
        $message = $validated['message'];

        $response = new StreamedResponse(function () use ($client, $user, $message, $options) {
            $this->relay($client, $user, $message, $options);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        // Tells nginx not to buffer the response into a single blob, which would
        // defeat streaming without producing any error.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * The conversation's messages, oldest first, so the widget can restore the
     * thread after a page reload. GAITH is the system of record for history.
     */
    public function messages(Request $request, string $conversation): JsonResponse
    {
        $user = $this->userOrAbort($request);
        $client = $this->client();
        $max = max(1, (int) config('gaith-chatbot.widget.history_max_messages', 500));

        $messages = [];
        $afterSeq = null;

        try {
            while (count($messages) < $max) {
                $page = $client->getMessages($user, $conversation, new MessagePageOptions($afterSeq, self::HISTORY_PAGE_SIZE));
                if ($page === []) {
                    break;
                }

                $previousSeq = $afterSeq;
                foreach ($page as $message) {
                    $files = [];
                    $metadata = $message->metadata();
                    // A clarifying question's clickable options, so a reload can show them again.
                    $question = $metadata !== null ? $metadata->question() : null;
                    if ($metadata !== null) {
                        // The user's uploads and the files tools produced, by artifact id;
                        // the widget fetches them through the file route above.
                        foreach (array_merge($metadata->attachments(), $metadata->files()) as $ref) {
                            $files[] = $this->filePayload($ref);
                        }
                    }
                    $messages[] = [
                        'role' => $message->role(),
                        'content' => $message->content(),
                        'created_at' => $message->createdAt(),
                        'files' => $files,
                        'options' => $question !== null ? $question->options() : [],
                    ];
                    $afterSeq = $message->seq();
                }

                if ($afterSeq === $previousSeq) {
                    break; // the cursor did not advance; stop rather than loop forever
                }
            }
        } catch (GaithNotFoundException | GaithForbiddenException $e) {
            // Expired, deleted, or not this user's: the widget starts a fresh conversation.
            return new JsonResponse(['code' => 'conversation_not_found'], 404);
        } catch (GaithApiException $e) {
            $this->logFailure('history_failed', $e);

            return new JsonResponse(['code' => 'gaith_error'], 502);
        }

        return new JsonResponse(['messages' => array_slice($messages, 0, $max)]);
    }

    /**
     * Consumes the SDK generator and writes one SSE frame per event.
     *
     * Any failure is reported as a terminal error frame rather than a dropped
     * socket, so the widget always has something definite to render.
     */
    private function relay(GaithChatbotClient $client, GaithUser $user, string $message, array $options): void
    {
        // The turn may legitimately run for minutes (tool calls); the resume window
        // inside the SDK, not PHP's time limit, bounds it.
        @set_time_limit(0);
        ignore_user_abort(false);

        try {
            foreach ($client->streamChat($user, $message, $options) as $event) {
                if ($event instanceof HeartbeatEvent) {
                    // Relayed as a comment so the browser connection is not left
                    // silent through a long tool call - a proxy in front of us would
                    // cull it well before GAITH is actually finished.
                    $this->emitComment();
                } else {
                    $this->emit($this->eventName($event), $this->eventPayload($event));
                }

                if (connection_aborted()) {
                    return; // leaving the loop closes the upstream stream via the SDK's finally
                }
            }
        } catch (StreamDroppedException $e) {
            $this->fail('stream_dropped', $e);
        } catch (GaithRateLimitException $e) {
            $this->fail('rate_limited', $e);
        } catch (GaithApiException $e) {
            $this->fail('gaith_error', $e);
        } catch (\Throwable $e) {
            $this->fail('internal_error', $e);
        }
    }

    private function userOrAbort(Request $request): GaithUser
    {
        $id = $this->users->resolve($request);
        if ($id === null || $id === '') {
            // This value partitions conversation history inside GAITH, so an
            // unidentified caller is refused rather than given an id of its own.
            abort(401);
        }

        return GaithUser::for($id);
    }

    private function client(): GaithChatbotClient
    {
        $connection = config('gaith-chatbot.widget.connection');
        if ($connection === null || $connection === '') {
            return $this->app->make(GaithChatbotClient::class);
        }

        return $this->app->make(GaithChatbotClientFactory::class)->get($connection);
    }

    /**
     * Logs the real cause and tells the browser only that the turn failed.
     */
    private function fail(string $code, \Throwable $e): void
    {
        $this->logFailure($code, $e);

        $this->emit('error', ['code' => $code, 'message' => 'The assistant could not complete this reply.']);
    }

    private function logFailure(string $code, \Throwable $e): void
    {
        Log::error('GAITH chat widget request failed', [
            'code' => $code,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    private function eventName(ChatEvent $event): string
    {
        if ($event instanceof TokenEvent) { return 'token'; }
        if ($event instanceof MetaEvent) { return 'meta'; }
        if ($event instanceof DoneEvent) { return 'done'; }
        if ($event instanceof ErrorEvent) { return 'error'; }
        if ($event instanceof LimitEvent) { return 'limit'; }
        if ($event instanceof FileEvent) { return 'file'; }
        if ($event instanceof ToolCallEvent) { return 'tool_call'; }
        if ($event instanceof ToolResultEvent) { return 'tool_result'; }
        if ($event instanceof SafetyBlockEvent) { return 'safety_block'; }
        if ($event instanceof QuestionEvent) { return 'question'; }

        return 'unknown';
    }

    /**
     * Only the fields the widget needs are forwarded - GAITH internals such as
     * stream ids and usage stay on this side.
     *
     * @return array<string, mixed>
     */
    private function eventPayload(ChatEvent $event): array
    {
        if ($event instanceof TokenEvent) {
            return ['content' => $event->content()];
        }
        if ($event instanceof MetaEvent) {
            return ['conversation_id' => $event->conversationId()];
        }
        if ($event instanceof DoneEvent) {
            return [
                'message_id' => $event->messageId(),
                'conversation_id' => $event->conversationId(),
                'finish_reason' => $event->finishReason(),
            ];
        }
        if ($event instanceof ErrorEvent) {
            return ['code' => $event->code(), 'message' => $event->message()];
        }
        if ($event instanceof LimitEvent) {
            return ['reason' => $event->reason()];
        }
        if ($event instanceof FileEvent) {
            return $this->filePayload($event->file());
        }
        if ($event instanceof ToolCallEvent) {
            return ['tool' => $event->tool()];
        }
        if ($event instanceof ToolResultEvent) {
            return ['tool' => $event->tool(), 'ok' => $event->ok()];
        }
        if ($event instanceof QuestionEvent) {
            return ['question' => $event->question()->question(), 'options' => $event->question()->options()];
        }

        return [];
    }

    /**
     * A file as the widget sees it. GAITH's download_url is deliberately left out:
     * it needs the API key, so the widget uses {prefix}/files/{artifact_id} instead.
     *
     * @return array<string, mixed>
     */
    private function filePayload(FileRef $file): array
    {
        return [
            'artifact_id' => $file->artifactId(),
            'filename' => $file->filename(),
            'media_type' => $file->mediaType(),
            'size_bytes' => $file->sizeBytes(),
        ];
    }

    /** Writes an SSE comment: keeps the socket warm, carries nothing. */
    private function emitComment(): void
    {
        echo ": heartbeat\n\n";
        $this->flushOutput();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emit(string $event, array $payload): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

        $this->flushOutput();
    }

    /** Both layers must be pushed: PHP's own buffers, then the SAPI's. */
    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
