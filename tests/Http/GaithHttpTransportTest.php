<?php

namespace Osos\Gaith\Sdk\Tests\Http;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Osos\Gaith\Sdk\Config\GaithChatbotConfig;
use Osos\Gaith\Sdk\Exceptions\GaithApiException;
use Osos\Gaith\Sdk\Exceptions\GaithAuthException;
use Osos\Gaith\Sdk\Exceptions\GaithForbiddenException;
use Osos\Gaith\Sdk\Exceptions\GaithGoneException;
use Osos\Gaith\Sdk\Exceptions\GaithNotFoundException;
use Osos\Gaith\Sdk\Exceptions\GaithRateLimitException;
use Osos\Gaith\Sdk\Exceptions\GaithValidationException;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

final class GaithHttpTransportTest extends TestCase
{
    private const CHATBOT_ID = '11111111-1111-1111-1111-111111111111';

    private function config(): GaithChatbotConfig
    {
        return new GaithChatbotConfig('https://gaith-backend-dev.osos.om', self::CHATBOT_ID, 'test-key');
    }

    private function fakeClient(Response $response, ?RequestInterface &$capturedRequest = null): ClientInterface
    {
        return new class ($response, $capturedRequest) implements ClientInterface {
            private $response;
            private $captured;

            public function __construct(Response $response, &$captured)
            {
                $this->response = $response;
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->captured = $request;

                return $this->response;
            }
        };
    }

    public function testGetSendsApiKeyHeaderAndComposedPath(): void
    {
        $captured = null;
        $client = $this->fakeClient(new Response(200, [], '{"name":"Bot"}'), $captured);
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        $result = $transport->get('meta');

        $this->assertSame(['name' => 'Bot'], $result);
        $this->assertSame('test-key', $captured->getHeaderLine('X-API-Key'));
        $this->assertSame(
            '/api/v1/chatbots/' . self::CHATBOT_ID . '/meta',
            $captured->getUri()->getPath()
        );
    }

    public function testGetAppendsQueryString(): void
    {
        $captured = null;
        $client = $this->fakeClient(new Response(200, [], '[]'), $captured);
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        $transport->get('conversations', 'external_user_id=user-1');

        $this->assertSame('external_user_id=user-1', $captured->getUri()->getQuery());
    }

    /**
     * @dataProvider statusToExceptionProvider
     */
    public function testStatusMapsToTypedException(int $status, string $expectedClass): void
    {
        $body = json_encode(['error' => ['code' => 'some_code', 'message' => 'Something went wrong']]);
        $client = $this->fakeClient(new Response($status, [], $body));
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        try {
            $transport->get('meta');
            $this->fail('Expected exception');
        } catch (GaithApiException $e) {
            $this->assertInstanceOf($expectedClass, $e);
            $this->assertSame($status, $e->statusCode());
            $this->assertSame('some_code', $e->serverCode());
            $this->assertSame('Something went wrong', $e->getMessage());
        }
    }

    public function statusToExceptionProvider(): array
    {
        return [
            [401, GaithAuthException::class],
            [403, GaithForbiddenException::class],
            [404, GaithNotFoundException::class],
            [410, GaithGoneException::class],
            [422, GaithValidationException::class],
            [429, GaithRateLimitException::class],
            [500, GaithApiException::class],
        ];
    }

    public function testFastApiDetailStringShape(): void
    {
        $body = json_encode(['detail' => 'Not authorized']);
        $client = $this->fakeClient(new Response(401, [], $body));
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        try {
            $transport->get('meta');
            $this->fail('Expected exception');
        } catch (GaithApiException $e) {
            $this->assertSame('Not authorized', $e->getMessage());
            $this->assertNull($e->serverCode());
        }
    }

    public function testFastApiDetailObjectShape(): void
    {
        $body = json_encode(['detail' => ['code' => 'bad_request', 'message' => 'Bad input']]);
        $client = $this->fakeClient(new Response(422, [], $body));
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        try {
            $transport->get('meta');
            $this->fail('Expected exception');
        } catch (GaithApiException $e) {
            $this->assertSame('Bad input', $e->getMessage());
            $this->assertSame('bad_request', $e->serverCode());
        }
    }

    public function testFastApiDetailValidationListShape(): void
    {
        $body = json_encode(['detail' => [
            ['loc' => ['body', 'message'], 'msg' => 'field required', 'type' => 'value_error.missing'],
        ]]);
        $client = $this->fakeClient(new Response(422, [], $body));
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        try {
            $transport->get('meta');
            $this->fail('Expected exception');
        } catch (GaithApiException $e) {
            $this->assertStringContainsString('field required', $e->getMessage());
        }
    }

    public function testBareCodeMessageShape(): void
    {
        $body = json_encode(['code' => 'rate_limited', 'message' => 'Too many requests']);
        $client = $this->fakeClient(new Response(429, [], $body));
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        try {
            $transport->get('meta');
            $this->fail('Expected exception');
        } catch (GaithApiException $e) {
            $this->assertSame('Too many requests', $e->getMessage());
            $this->assertSame('rate_limited', $e->serverCode());
        }
    }

    public function testUnparseableBodyFallsBackToRawBodyOrReasonPhrase(): void
    {
        $client = $this->fakeClient(new Response(500, [], 'not json'));
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        try {
            $transport->get('meta');
            $this->fail('Expected exception');
        } catch (GaithApiException $e) {
            $this->assertSame('not json', $e->getMessage());
            $this->assertSame('not json', $e->responseBody());
        }
    }

    public function testDeleteSendsDeleteMethodAndReturnsVoid(): void
    {
        $captured = null;
        $client = $this->fakeClient(new Response(204, [], ''), $captured);
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        $transport->delete('conversations/abc', 'external_user_id=user-1');

        $this->assertSame('DELETE', $captured->getMethod());
        $this->assertSame(
            '/api/v1/chatbots/' . self::CHATBOT_ID . '/conversations/abc',
            $captured->getUri()->getPath()
        );
    }

    public function testPostJsonSendsBodyAndReturnsDecodedResponse(): void
    {
        $captured = null;
        $client = $this->fakeClient(new Response(200, [], '{"ok":true}'), $captured);
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        $result = $transport->postJson('chat', ['message' => 'hi', 'external_user_id' => 'u1']);

        $this->assertSame(['ok' => true], $result);
        $this->assertSame('POST', $captured->getMethod());
        $this->assertSame('application/json', $captured->getHeaderLine('Content-Type'));
        $sentBody = json_decode((string) $captured->getBody(), true);
        $this->assertSame(['message' => 'hi', 'external_user_id' => 'u1'], $sentBody);
    }

    public function testPostJsonMergesExtraHeaders(): void
    {
        $captured = null;
        $client = $this->fakeClient(new Response(200, [], '{}'), $captured);
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        $transport->postJson('chat', ['message' => 'hi'], ['Last-Event-ID' => '5']);

        $this->assertSame('5', $captured->getHeaderLine('Last-Event-ID'));
    }

    public function testPostMultipartSendsFileAndUserIdParts(): void
    {
        $captured = null;
        $responseBody = json_encode(['artifact_id' => 'a1', 'filename' => 'x.txt']);
        $client = $this->fakeClient(new Response(200, [], $responseBody), $captured);
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        $result = $transport->postMultipart('attachments', 'file bytes', 'x.txt', 'user-1');

        $this->assertSame('a1', $result['artifact_id']);
        $this->assertSame('POST', $captured->getMethod());
        $this->assertStringContainsString('multipart/form-data', $captured->getHeaderLine('Content-Type'));
        $rawBody = (string) $captured->getBody();
        $this->assertStringContainsString('name="file"', $rawBody);
        $this->assertStringContainsString('filename="x.txt"', $rawBody);
        $this->assertStringContainsString('name="external_user_id"', $rawBody);
        $this->assertStringContainsString('user-1', $rawBody);
        $this->assertStringContainsString('file bytes', $rawBody);
    }

    public function testGetRawReturnsRawStream(): void
    {
        $captured = null;
        $client = $this->fakeClient(new Response(200, [], 'raw bytes'), $captured);
        $factory = new HttpFactory();
        $transport = new GaithHttpTransport($this->config(), $client, $factory, $factory);

        $stream = $transport->getRaw('attachments/artifact-1', 'external_user_id=user-1');

        $this->assertSame('raw bytes', (string) $stream);
        $this->assertSame('GET', $captured->getMethod());
    }
}
