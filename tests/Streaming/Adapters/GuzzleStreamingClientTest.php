<?php

namespace Osos\Gaith\Sdk\Tests\Streaming\Adapters;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Osos\Gaith\Sdk\Exceptions\GaithApiException;
use Osos\Gaith\Sdk\Streaming\Adapters\GuzzleStreamingClient;
use Osos\Gaith\Sdk\Streaming\StreamDroppedException;
use PHPUnit\Framework\TestCase;

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
}
