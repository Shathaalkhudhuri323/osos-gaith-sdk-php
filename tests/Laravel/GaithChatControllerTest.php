<?php

namespace Osos\Gaith\Sdk\Tests\Laravel;

use GuzzleHttp\Psr7\Request;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Osos\Gaith\Sdk\Exceptions\GaithNotFoundException;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Laravel\GaithChatbotServiceProvider;
use Osos\Gaith\Sdk\Streaming\StreamDroppedException;
use Osos\Gaith\Sdk\Streaming\StreamHandle;
use Osos\Gaith\Sdk\Streaming\StreamingHttpClientInterface;
use Orchestra\Testbench\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Covers the widget's proxy routes. The SDK client is real but its transports are
 * stubbed, so these exercise the bridge's own event mapping, SSE framing, user
 * scoping and failure handling without touching the network.
 */
final class GaithChatControllerTest extends TestCase
{
    private const SSE_HAPPY_PATH = [
        "event: meta\ndata: {\"conversation_id\":\"c1\",\"user_message_id\":\"um1\",\"stream_id\":\"s1\"}\n\n",
        "event: token\ndata: {\"content\":\"Hel\"}\n\n",
        "event: token\ndata: {\"content\":\"lo\"}\n\n",
        "event: done\ndata: {\"message_id\":\"m1\",\"conversation_id\":\"c1\",\"finish_reason\":\"stop\",\"usage\":{\"input_tokens\":3,\"output_tokens\":2}}\n\n",
    ];

    /** @var array<string, mixed>|null body of the last outgoing /chat request */
    private $sentChatBody;

    /** @var array<int, string> query strings of outgoing GET requests */
    private $sentQueries = [];

    protected function getPackageProviders($app)
    {
        return [GaithChatbotServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('gaith-chatbot.widget.enabled', true);
        $app['config']->set('gaith-chatbot.widget.prefix', 'api/gaith');
        $app['config']->set('gaith-chatbot.widget.middleware', ['auth']);
        $app['config']->set('gaith-chatbot.widget.user_prefix', 'erp-user-');
    }

    private function signIn(int $id = 42): void
    {
        $this->actingAs(new GenericUser(['id' => $id]));
    }

    public function testItStreamsGaithEventsToTheBrowserAsSse(): void
    {
        $this->signIn();
        $this->bindStreaming(self::SSE_HAPPY_PATH);

        $response = $this->post('/api/gaith/stream', ['message' => 'hello']);

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        // Without this nginx buffers the whole stream into one blob.
        $response->assertHeader('X-Accel-Buffering', 'no');

        $body = $response->streamedContent();

        $this->assertStringContainsString("event: meta\ndata: {\"conversation_id\":\"c1\"}\n\n", $body);
        $this->assertStringContainsString("event: token\ndata: {\"content\":\"Hel\"}\n\n", $body);
        $this->assertStringContainsString("event: token\ndata: {\"content\":\"lo\"}\n\n", $body);
        $this->assertStringContainsString(
            "event: done\ndata: {\"message_id\":\"m1\",\"conversation_id\":\"c1\",\"finish_reason\":\"stop\"}\n\n",
            $body
        );
    }

    public function testItDoesNotLeakGaithInternalsToTheBrowser(): void
    {
        $this->signIn();
        $this->bindStreaming(self::SSE_HAPPY_PATH);

        $body = $this->post('/api/gaith/stream', ['message' => 'hello'])->streamedContent();

        // Resume-protocol internals and usage have no use in an untrusted client.
        $this->assertStringNotContainsString('stream_id', $body);
        $this->assertStringNotContainsString('user_message_id', $body);
        $this->assertStringNotContainsString('usage', $body);
    }

    public function testItScopesTheTurnToTheSignedInUserAndForwardsTheConversation(): void
    {
        $this->signIn(42);
        $this->bindStreaming(self::SSE_HAPPY_PATH);

        $this->post('/api/gaith/stream', [
            'message' => 'hello',
            'conversation_id' => 'c1',
            // A browser must not be able to choose whose history it writes to.
            'external_user_id' => 'someone-else',
        ])->streamedContent();

        $this->assertSame('erp-user-42', $this->sentChatBody['external_user_id']);
        $this->assertSame('hello', $this->sentChatBody['message']);
        $this->assertSame('c1', $this->sentChatBody['conversation_id']);
    }

    public function testItRelaysUpstreamKeepAlivesAsComments(): void
    {
        $this->signIn();
        $this->bindStreaming([
            ":heartbeat\n\n",
            "event: token\ndata: {\"content\":\"hi\"}\n\n",
            ":heartbeat\n\n",
            "event: done\ndata: {\"message_id\":\"m1\",\"conversation_id\":\"c1\",\"finish_reason\":\"stop\",\"usage\":{\"input_tokens\":1,\"output_tokens\":1}}\n\n",
        ]);

        $body = $this->post('/api/gaith/stream', ['message' => 'hello'])->streamedContent();

        $this->assertSame(2, substr_count($body, ": heartbeat\n\n"));
        // A comment must not be dressed up as an event the client would try to parse.
        $this->assertStringNotContainsString('event: heartbeat', $body);
        $this->assertStringContainsString('event: token', $body);
    }

    public function testItReportsADroppedStreamAsATerminalErrorFrame(): void
    {
        $this->signIn();
        $this->bindHandle(new class implements StreamHandle {
            public function read(): ?string { throw new StreamDroppedException('connection reset'); }
            public function close(): void {}
        });

        $body = $this->post('/api/gaith/stream', ['message' => 'hello'])->streamedContent();

        // A dead socket would leave the widget spinning, so the failure always
        // becomes something the client can render and finish on.
        $this->assertStringContainsString("event: error\ndata: {\"code\":\"stream_dropped\"", $body);
        $this->assertStringNotContainsString('connection reset', $body);
    }

    public function testItRefusesUnauthenticatedCallers(): void
    {
        $this->bindStreaming(self::SSE_HAPPY_PATH);

        $this->postJson('/api/gaith/stream', ['message' => 'hello'])->assertStatus(401);
        $this->getJson('/api/gaith/conversations/c1/messages')->assertStatus(401);
    }

    public function testItRefusesACallerTheResolverCannotIdentify(): void
    {
        // A signed-in user with no identifier must not be given a history bucket.
        $this->actingAs(new GenericUser(['id' => null]));
        $this->bindStreaming(self::SSE_HAPPY_PATH);

        $this->postJson('/api/gaith/stream', ['message' => 'hello'])->assertStatus(401);
    }

    public function testItRejectsAnEmptyMessage(): void
    {
        $this->signIn();
        $this->bindStreaming(self::SSE_HAPPY_PATH);

        $this->postJson('/api/gaith/stream', ['message' => ''])->assertStatus(422);
    }

    public function testItLoadsHistoryPageByPageUntilAnEmptyPage(): void
    {
        $this->signIn(42);
        $pages = [
            [$this->messageRow('user', 'hi', 1), $this->messageRow('assistant', 'hello', 2)],
            [$this->messageRow('user', 'more', 3)],
            [],
        ];
        $this->bindJson(function (string $path) use (&$pages) {
            return array_shift($pages);
        });

        $response = $this->getJson('/api/gaith/conversations/c1/messages');

        $response->assertStatus(200);
        $response->assertExactJson(['messages' => [
            ['role' => 'user', 'content' => 'hi', 'created_at' => '2026-01-01T00:00:01Z', 'files' => [], 'options' => []],
            ['role' => 'assistant', 'content' => 'hello', 'created_at' => '2026-01-01T00:00:02Z', 'files' => [], 'options' => []],
            ['role' => 'user', 'content' => 'more', 'created_at' => '2026-01-01T00:00:03Z', 'files' => [], 'options' => []],
        ]]);
        // Each page continues after the last seq seen, always as the signed-in user.
        $this->assertStringNotContainsString('after_seq', $this->sentQueries[0]);
        $this->assertStringContainsString('after_seq=2', $this->sentQueries[1]);
        $this->assertStringContainsString('after_seq=3', $this->sentQueries[2]);
        $this->assertStringContainsString('external_user_id=erp-user-42', $this->sentQueries[0]);
    }

    public function testHistoryIsCappedSoOneLongConversationCannotHoldTheWorker(): void
    {
        $this->signIn();
        $this->app['config']->set('gaith-chatbot.widget.history_max_messages', 2);
        $seq = 0;
        $this->bindJson(function () use (&$seq) {
            $seq++;
            return [$this->messageRow('user', 'm' . $seq, $seq)];
        });

        $response = $this->getJson('/api/gaith/conversations/c1/messages');

        $this->assertCount(2, $response->json('messages'));
        $this->assertCount(2, $this->sentQueries);
    }

    public function testHistoryForAnUnknownConversationIsANotFound(): void
    {
        $this->signIn();
        $this->bindJson(function () {
            throw new GaithNotFoundException(404, 'not_found', 'Conversation not found', '{}');
        });

        $this->getJson('/api/gaith/conversations/gone/messages')
            ->assertStatus(404)
            ->assertExactJson(['code' => 'conversation_not_found']);
    }

    public function testConfigServesThePerProductThemeColours(): void
    {
        $this->signIn();
        $this->app['config']->set('gaith-chatbot.widget.theme', [
            'primary_color' => '#E33A33',
            'text_color' => 'rgb(255, 255, 255)',
        ]);

        $this->getJson('/api/gaith/config')
            ->assertStatus(200)
            ->assertJsonPath('theme', ['primary_color' => '#E33A33', 'text_color' => 'rgb(255, 255, 255)']);
    }

    public function testConfigDropsValuesThatAreNotColours(): void
    {
        // A typo or injected value must not reach the browser's CSS.
        $this->signIn();
        $this->app['config']->set('gaith-chatbot.widget.theme', [
            'primary_color' => 'red; background: url(https://evil.example)',
            'text_color' => '#fff',
        ]);

        $this->getJson('/api/gaith/config')->assertJsonPath('theme', ['text_color' => '#fff']);
    }

    public function testConfigDefaultsToBlue(): void
    {
        $this->signIn();

        $this->getJson('/api/gaith/config')
            ->assertJsonPath('theme', ['primary_color' => '#1f6feb', 'text_color' => '#ffffff']);
    }

    public function testConfigServesOnlyValidImageOrigins(): void
    {
        $this->signIn();
        $this->app['config']->set('gaith-chatbot.widget.image_origins', [
            'https://QHSE-mcp.example.com/',
            'http://localhost:8080',
            'javascript:alert(1)',
            'https://example.com/path/not-an-origin',
        ]);

        $this->getJson('/api/gaith/config')
            ->assertJsonPath('image_origins', ['https://qhse-mcp.example.com', 'http://localhost:8080']);
    }

    public function testFileEventsCarryNoGaithUrl(): void
    {
        // GAITH's download_url needs the API key; the widget must use our file route.
        $this->signIn();
        $this->bindStreaming([
            "event: file\ndata: {\"artifact_id\":\"6910c7b6-6e62-4498-b880-611d2b159ba9\",\"filename\":\"onboarding-checklist.pdf\",\"media_type\":\"application/pdf\",\"size_bytes\":36979,\"download_url\":\"/api/v1/chatbots/x/attachments/6910c7b6?external_user_id=erp-user-42\"}\n\n",
            "event: done\ndata: {\"message_id\":\"m1\",\"conversation_id\":\"c1\",\"finish_reason\":\"stop\",\"usage\":{\"input_tokens\":1,\"output_tokens\":1}}\n\n",
        ]);

        $body = $this->post('/api/gaith/stream', ['message' => 'make a pdf'])->streamedContent();

        $this->assertStringContainsString(
            "event: file\ndata: {\"artifact_id\":\"6910c7b6-6e62-4498-b880-611d2b159ba9\",\"filename\":\"onboarding-checklist.pdf\",\"media_type\":\"application/pdf\",\"size_bytes\":36979}\n\n",
            $body
        );
        $this->assertStringNotContainsString('download_url', $body);
    }

    public function testFileRouteServesAPdfInlineAsTheSignedInUser(): void
    {
        $this->signIn(42);
        $this->bindFile(new \GuzzleHttp\Psr7\Response(200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="onboarding-checklist.pdf"',
            'Content-Length' => '8',
        ], '%PDF-1.3'));

        $response = $this->get('/api/gaith/files/6910c7b6-6e62-4498-b880-611d2b159ba9');

        $response->assertStatus(200);
        $this->assertSame('%PDF-1.3', $response->streamedContent());
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline; filename="onboarding-checklist.pdf"', $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringNotContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertSame('attachments/6910c7b6-6e62-4498-b880-611d2b159ba9', $this->sentFilePath);
        $this->assertSame('external_user_id=erp-user-42', $this->sentQueries[0]);
    }

    public function testFileRouteTreatsArtifactIdsAsOpaqueValues(): void
    {
        $this->signIn();
        $this->bindFile(new \GuzzleHttp\Psr7\Response(200, [
            'Content-Type' => 'application/octet-stream',
        ], 'bytes'));

        $this->get('/api/gaith/files/artifact-1')->assertStatus(200);

        $this->assertSame('attachments/artifact-1', $this->sentFilePath);
    }

    public function testFileRouteForcesADownloadWhenAsked(): void
    {
        $this->signIn();
        $this->bindFile(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'image/png'], 'png'));

        $response = $this->get('/api/gaith/files/6910c7b6-6e62-4498-b880-611d2b159ba9?download=1');

        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function testFileRouteNeverServesScriptableTypesInline(): void
    {
        // An SVG or HTML file opened on the host's origin could run script as the host app.
        $this->signIn();
        $this->bindFile(new \GuzzleHttp\Psr7\Response(200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => "attachment; filename*=UTF-8''%D8%B5%D9%88%D8%B1%D8%A9.svg",
        ], '<svg onload="alert(1)"/>'));

        $response = $this->get('/api/gaith/files/6910c7b6-6e62-4498-b880-611d2b159ba9');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''%D8%B5%D9%88%D8%B1%D8%A9.svg", $disposition); // Arabic name kept
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function testFileRouteMapsGaithErrors(): void
    {
        $this->signIn();

        $this->bindFile(new \GuzzleHttp\Psr7\Response(404, [], '{"detail":"Artifact not found."}'));
        $this->getJson('/api/gaith/files/6910c7b6-6e62-4498-b880-611d2b159ba9')
            ->assertStatus(404)->assertExactJson(['code' => 'file_not_found']);

        $this->bindFile(new \GuzzleHttp\Psr7\Response(410, [], '{"detail":"Artifact has expired."}'));
        $this->getJson('/api/gaith/files/6910c7b6-6e62-4498-b880-611d2b159ba9')
            ->assertStatus(410)->assertExactJson(['code' => 'file_expired']);
    }

    public function testFileRouteRejectsMalformedIdsWithoutCallingGaith(): void
    {
        $this->signIn();
        $this->bindFile(new \GuzzleHttp\Psr7\Response(200, [], 'x'));

        $this->getJson('/api/gaith/files/..%2F..%2Fmeta')->assertStatus(404);
        $this->assertNull($this->sentFilePath);
    }

    public function testFileRouteRefusesUnauthenticatedCallers(): void
    {
        $this->getJson('/api/gaith/files/6910c7b6-6e62-4498-b880-611d2b159ba9')->assertStatus(401);
    }

    public function testItRelaysAClarifyingQuestionWithItsOptions(): void
    {
        $this->signIn();
        $this->bindStreaming([
            "event: meta\ndata: {\"conversation_id\":\"c1\",\"user_message_id\":\"um1\",\"stream_id\":\"s1\"}\n\n",
            "event: token\ndata: {\"content\":\"Which month?\"}\n\n",
            "event: question\ndata: {\"id\":\"call_1\",\"question\":\"Which month?\",\"options\":[\"October\",\"November\"]}\n\n",
            "event: done\ndata: {\"message_id\":\"m1\",\"conversation_id\":\"c1\",\"finish_reason\":\"question\",\"usage\":{\"input_tokens\":1,\"output_tokens\":1}}\n\n",
        ]);

        $body = $this->post('/api/gaith/stream', ['message' => 'show my leave'])->streamedContent();

        $this->assertStringContainsString(
            "event: question\ndata: {\"question\":\"Which month?\",\"options\":[\"October\",\"November\"]}\n\n",
            $body
        );
        $this->assertStringContainsString('"finish_reason":"question"', $body);
    }

    public function testHistoryKeepsTheOptionsOfAClarifyingQuestion(): void
    {
        $this->signIn();
        $pages = [[
            array_merge($this->messageRow('assistant', 'Which month?', 1), ['metadata' => [
                'finish_reason' => 'question',
                'tool_events' => [['event' => 'question', 'id' => 'call_1', 'question' => 'Which month?', 'options' => ['October', 'November']]],
            ]]),
        ], []];
        $this->bindJson(function () use (&$pages) {
            return array_shift($pages);
        });

        $messages = $this->getJson('/api/gaith/conversations/c1/messages')->json('messages');

        $this->assertSame(['October', 'November'], $messages[0]['options']);
    }

    public function testHistoryIncludesUploadsAndToolFiles(): void
    {
        $this->signIn();
        $pages = [[
            $this->messageRow('user', 'here is the photo', 1) + ['metadata' => null],
            array_merge($this->messageRow('user', 'with metadata', 2), ['metadata' => ['attachments' => [
                // GAITH stores user uploads without a download_url.
                ['artifact_id' => 'up-1', 'filename' => 'site.jpg', 'media_type' => 'image/jpeg', 'size_bytes' => 2048],
            ]]]),
            array_merge($this->messageRow('assistant', 'done', 3), ['metadata' => ['files' => [
                ['artifact_id' => 'out-1', 'filename' => 'report.pdf', 'media_type' => 'application/pdf', 'size_bytes' => 100, 'download_url' => '/api/v1/x'],
            ]]]),
        ], []];
        $this->bindJson(function () use (&$pages) {
            return array_shift($pages);
        });

        $messages = $this->getJson('/api/gaith/conversations/c1/messages')->json('messages');

        $this->assertSame([], $messages[0]['files']);
        $this->assertSame([['artifact_id' => 'up-1', 'filename' => 'site.jpg', 'media_type' => 'image/jpeg', 'size_bytes' => 2048]], $messages[1]['files']);
        $this->assertSame([['artifact_id' => 'out-1', 'filename' => 'report.pdf', 'media_type' => 'application/pdf', 'size_bytes' => 100]], $messages[2]['files']);
    }

    /** @var string|null path of the last outgoing raw (file) request */
    private $sentFilePath;

    /**
     * Binds a client whose JSON transport answers file downloads with $response
     * through the real error mapping, as GAITH would.
     */
    private function bindFile(\GuzzleHttp\Psr7\Response $response): void
    {
        $this->sentFilePath = null;
        $this->sentQueries = [];
        $httpClient = new class ($response) implements \Psr\Http\Client\ClientInterface {
            private $response;
            public $request;
            public function __construct($response) { $this->response = $response; }
            public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->request = $request;
                return $this->response;
            }
        };
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $config = new \Osos\Gaith\Sdk\Config\GaithChatbotConfig('https://gaith.invalid', '11111111-1111-1111-1111-111111111111', 'k');
        $real = new GaithHttpTransport($config, $httpClient, $factory, $factory);

        $transport = $this->createStub(GaithHttpTransport::class);
        $transport->method('getRawResponse')->willReturnCallback(
            function (string $path, string $query = '') use ($real) {
                $this->sentFilePath = $path;
                $this->sentQueries[] = $query;
                return $real->getRawResponse($path, $query);
            }
        );

        $this->app->instance(GaithChatbotClient::class, new GaithChatbotClient($transport, $this->createStub(StreamingHttpClientInterface::class)));
    }

    /**
     * @return array<string, mixed>
     */
    private function messageRow(string $role, string $content, int $seq): array
    {
        return [
            'id' => 'm' . $seq,
            'conversation_id' => 'c1',
            'role' => $role,
            'content' => $content,
            'seq' => $seq,
            'created_at' => sprintf('2026-01-01T00:00:%02dZ', $seq),
            'metadata' => null,
        ];
    }

    /**
     * @param string[] $chunks
     */
    private function bindStreaming(array $chunks): void
    {
        $this->bindHandle(new class ($chunks) implements StreamHandle {
            private $chunks;
            private $i = 0;
            public function __construct(array $chunks) { $this->chunks = $chunks; }
            public function read(): ?string { return $this->chunks[$this->i++] ?? null; }
            public function close(): void {}
        });
    }

    private function bindHandle(StreamHandle $handle): void
    {
        $streaming = new class ($handle) implements StreamingHttpClientInterface {
            private $handle;
            public function __construct(StreamHandle $handle) { $this->handle = $handle; }
            public function sendStreaming(RequestInterface $request): StreamHandle { return $this->handle; }
        };

        // The transport is stubbed, so buildStreamingRequest is where the outgoing
        // payload is observed - the returned request never reaches the network.
        $transport = $this->createStub(GaithHttpTransport::class);
        $transport->method('buildStreamingRequest')->willReturnCallback(
            function (string $path, array $body): RequestInterface {
                $this->sentChatBody = $body;
                return new Request('POST', 'https://gaith.invalid/' . $path);
            }
        );

        $this->app->instance(GaithChatbotClient::class, new GaithChatbotClient($transport, $streaming));
    }

    public function testUploadForwardsTheFileToGaithAsTheSignedInUser(): void
    {
        $this->signIn(42);
        $this->bindUploads();

        $response = $this->post('/api/gaith/attachments', [
            'file' => UploadedFile::fake()->create('site photo.jpg', 120, 'image/jpeg'),
        ]);

        $response->assertStatus(201)->assertExactJson([
            'artifact_id' => 'art-1',
            'filename' => 'site photo.jpg',
            'media_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ]);
        [$name, $user, $type] = $this->sentUploads[0];
        $this->assertSame('site photo.jpg', $name);
        $this->assertSame('erp-user-42', $user);
        $this->assertSame('image/jpeg', $type);
    }

    public function testAMessageCarriesTheUsersOwnUploads(): void
    {
        $this->signIn(42);
        $this->bindUploads();
        $id = $this->post('/api/gaith/attachments', ['file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')])
            ->json('artifact_id');

        $this->post('/api/gaith/stream', ['message' => 'summarise this', 'attachments' => [$id]])->streamedContent();

        $this->assertSame(['art-1'], $this->sentChatBody['attachments']);
    }

    public function testAMessageCannotBorrowAnotherUsersUpload(): void
    {
        // GAITH resolves attachment ids per chatbot, not per user: the bridge must refuse.
        $this->signIn(42);
        $this->bindUploads();
        $id = $this->post('/api/gaith/attachments', ['file' => UploadedFile::fake()->create('payslip.pdf', 10, 'application/pdf')])
            ->json('artifact_id');

        $this->signIn(7);
        $this->postJson('/api/gaith/stream', ['message' => 'what is in it?', 'attachments' => [$id]])
            ->assertStatus(422)
            ->assertExactJson(['code' => 'attachment_not_found']);
        $this->assertNull($this->sentChatBody); // never reached GAITH
    }

    public function testUploadRejectsATypeGaithWouldRefuseWithoutCallingIt(): void
    {
        $this->signIn();
        $this->bindUploads();

        $this->postJson('/api/gaith/attachments', ['file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])
            ->assertStatus(422)
            ->assertExactJson(['code' => 'file_type_not_allowed']);
        $this->assertSame([], $this->sentUploads);
    }

    public function testUploadRejectsAFileOverTheSizeLimit(): void
    {
        $this->signIn();
        $this->bindUploads();
        $this->app['config']->set('gaith-chatbot.widget.attachments.max_bytes', 1024);

        $this->postJson('/api/gaith/attachments', ['file' => UploadedFile::fake()->create('big.pdf', 2, 'application/pdf')])
            ->assertStatus(413)
            ->assertExactJson(['code' => 'file_too_large']);
        $this->assertSame([], $this->sentUploads);
    }

    public function testUploadReportsAFileGaithRejects(): void
    {
        $this->signIn();
        $this->bindUploads(function () {
            throw new \Osos\Gaith\Sdk\Exceptions\GaithValidationException(422, null, 'File content does not match its type.', '{}');
        });

        $this->postJson('/api/gaith/attachments', ['file' => UploadedFile::fake()->create('fake.pdf', 1, 'application/pdf')])
            ->assertStatus(422)
            ->assertExactJson(['code' => 'file_rejected']);
    }

    public function testUploadNeedsASignedInUserAndCanBeTurnedOff(): void
    {
        $this->bindUploads();
        $this->postJson('/api/gaith/attachments', ['file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')])
            ->assertStatus(401);

        $this->signIn();
        $this->app['config']->set('gaith-chatbot.widget.attachments.enabled', false);
        $this->postJson('/api/gaith/attachments', ['file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')])
            ->assertStatus(404);
        $this->assertSame([], $this->sentUploads);
    }

    public function testConfigTellsTheWidgetTheAttachmentLimits(): void
    {
        $this->signIn();

        $this->getJson('/api/gaith/config')->assertJsonPath('attachments', [
            'enabled' => true,
            'max_files' => 8,
            'max_bytes' => 20971520,
            'allowed_types' => [
                'application/pdf',
                'image/png',
                'image/jpeg',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ]);
    }

    /** @var array<int, array{0: string, 1: string, 2: ?string}> uploads sent to GAITH: name, user, type */
    private $sentUploads = [];

    /**
     * Binds a client whose transport accepts uploads (answering with $respond, or a
     * default artifact) and chat turns, recording what reached "GAITH".
     */
    private function bindUploads(?callable $respond = null): void
    {
        $this->sentUploads = [];
        $this->sentChatBody = null;
        $transport = $this->createStub(GaithHttpTransport::class);
        $transport->method('postMultipart')->willReturnCallback(
            function (string $path, string $contents, string $filename, string $userId, ?string $type = null) use ($respond): array {
                $this->sentUploads[] = [$filename, $userId, $type];
                if ($respond !== null) {
                    return $respond();
                }
                return [
                    'artifact_id' => 'art-' . count($this->sentUploads),
                    'filename' => $filename,
                    'media_type' => $type,
                    'size_bytes' => 2048, // as GAITH reports it; the route relays it
                    'expires_at' => null,
                    'download_url' => '/api/v1/x',
                ];
            }
        );
        $transport->method('buildStreamingRequest')->willReturnCallback(
            function (string $path, array $body): RequestInterface {
                $this->sentChatBody = $body;
                return new Request('POST', 'https://gaith.invalid/' . $path);
            }
        );
        $streaming = new class (self::SSE_HAPPY_PATH) implements StreamingHttpClientInterface {
            private $chunks;
            public function __construct(array $chunks) { $this->chunks = $chunks; }
            public function sendStreaming(RequestInterface $request): StreamHandle
            {
                return new class ($this->chunks) implements StreamHandle {
                    private $chunks;
                    private $i = 0;
                    public function __construct(array $chunks) { $this->chunks = $chunks; }
                    public function read(): ?string { return $this->chunks[$this->i++] ?? null; }
                    public function close(): void {}
                };
            }
        };

        $this->app->instance(GaithChatbotClient::class, new GaithChatbotClient($transport, $streaming));
    }

    private function bindJson(callable $respond): void
    {
        $transport = $this->createStub(GaithHttpTransport::class);
        $transport->method('get')->willReturnCallback(
            function (string $path, string $query = '') use ($respond): array {
                $this->sentQueries[] = $query;
                return $respond($path, $query);
            }
        );
        $streaming = $this->createStub(StreamingHttpClientInterface::class);

        $this->app->instance(GaithChatbotClient::class, new GaithChatbotClient($transport, $streaming));
    }
}
