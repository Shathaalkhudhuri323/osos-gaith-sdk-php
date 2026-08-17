<?php

namespace Osos\Gaith\Sdk\Tests\Streaming\Adapters;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Osos\Gaith\Sdk\Exceptions\GaithApiException;
use Osos\Gaith\Sdk\Exceptions\GaithAuthException;
use Osos\Gaith\Sdk\Exceptions\GaithForbiddenException;
use Osos\Gaith\Sdk\Exceptions\GaithGoneException;
use Osos\Gaith\Sdk\Exceptions\GaithNotFoundException;
use Osos\Gaith\Sdk\Exceptions\GaithRateLimitException;
use Osos\Gaith\Sdk\Exceptions\GaithValidationException;
use Osos\Gaith\Sdk\Streaming\Adapters\GuzzleStreamHandle;
use Osos\Gaith\Sdk\Streaming\Adapters\GuzzleStreamingClient;
use Osos\Gaith\Sdk\Streaming\StreamDroppedException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class GuzzleStreamingClientTest extends TestCase
{
    private function requestFactory(): HttpFactory
    {
        return new HttpFactory();
    }

    public function testSendStreamingReturnsHandleThatReadsChunks(): void
    {
        $mock = new MockHandler([new Response(200, [], "event: token\ndata: {}\n\n")]);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        $adapter = new GuzzleStreamingClient($guzzle);
        $request = $this->requestFactory()->createRequest('POST', 'https://example.test/chat');

        $handle = $adapter->sendStreaming($request);

        $collected = '';
        while (($chunk = $handle->read()) !== null) {
            $collected .= $chunk;
        }

        $this->assertSame("event: token\ndata: {}\n\n", $collected);
    }

    public function testNon2xxResponseThrowsGaithApiException(): void
    {
        $mock = new MockHandler([new Response(401, [], json_encode(['error' => ['code' => 'unauthorized', 'message' => 'bad key']]))]);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        $adapter = new GuzzleStreamingClient($guzzle);
        $request = $this->requestFactory()->createRequest('POST', 'https://example.test/chat');

        $this->expectException(GaithApiException::class);

        $adapter->sendStreaming($request);
    }

    public function testConnectExceptionThrowsStreamDroppedExceptionOnRead(): void
    {
        $mock = new MockHandler([
            new \GuzzleHttp\Exception\ConnectException('connection reset', new \GuzzleHttp\Psr7\Request('POST', 'https://example.test/chat')),
        ]);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        $adapter = new GuzzleStreamingClient($guzzle);
        $request = $this->requestFactory()->createRequest('POST', 'https://example.test/chat');

        $this->expectException(StreamDroppedException::class);

        $adapter->sendStreaming($request);
    }

    /**
     * @dataProvider statusToExceptionProvider
     */
    public function testStatusMapsToTypedException(int $status, string $expectedClass): void
    {
        $body = json_encode(['error' => ['code' => 'some_code', 'message' => 'Something went wrong']]);
        $mock = new MockHandler([new Response($status, [], $body)]);
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        $adapter = new GuzzleStreamingClient($guzzle);
        $request = $this->requestFactory()->createRequest('POST', 'https://example.test/chat');

        try {
            $adapter->sendStreaming($request);
            $this->fail('Expected exception');
        } catch (GaithApiException $e) {
            $this->assertInstanceOf($expectedClass, $e);
            $this->assertSame($status, $e->statusCode());
            $this->assertSame('some_code', $e->serverCode());
            $this->assertSame('Something went wrong', $e->getMessage());
        }
    }

    public function testStalledReadWithEmptyChunkBeforeEofThrowsStreamDroppedException(): void
    {
        $body = new class implements StreamInterface {
            private $eofCalls = 0;

            public function __toString(): string { return ''; }
            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return null; }
            public function tell(): int { return 0; }
            public function eof(): bool
            {
                // First check (before read) says "not EOF"; the recheck after the
                // empty read also says "not EOF" -- simulating a stalled connection.
                $this->eofCalls++;
                return false;
            }
            public function isSeekable(): bool { return false; }
            public function seek($offset, $whence = SEEK_SET): void {}
            public function rewind(): void {}
            public function isWritable(): bool { return false; }
            public function write($string): int { return 0; }
            public function isReadable(): bool { return true; }
            public function read($length): string { return ''; }
            public function getContents(): string { return ''; }
            public function getMetadata($key = null) { return null; }
        };

        $handle = new GuzzleStreamHandle($body);

        $this->expectException(StreamDroppedException::class);

        $handle->read();
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
}
