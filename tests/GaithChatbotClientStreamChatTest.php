<?php

namespace Osos\Gaith\Sdk\Tests;

use Osos\Gaith\Sdk\Config\GaithUser;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Streaming\DoneEvent;
use Osos\Gaith\Sdk\Streaming\MetaEvent;
use Osos\Gaith\Sdk\Streaming\StreamDroppedException;
use Osos\Gaith\Sdk\Streaming\StreamHandle;
use Osos\Gaith\Sdk\Streaming\StreamingHttpClientInterface;
use Osos\Gaith\Sdk\Streaming\TokenEvent;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class GaithChatbotClientStreamChatTest extends TestCase
{
    private function handleFor(array $chunks): StreamHandle
    {
        return new class ($chunks) implements StreamHandle {
            private $chunks;
            private $i = 0;
            public function __construct(array $chunks) { $this->chunks = $chunks; }
            public function read(): ?string { return $this->chunks[$this->i++] ?? null; }
            public function close(): void {}
        };
    }

    private function droppingHandleThenNull(array $chunksBeforeDrop): StreamHandle
    {
        return new class ($chunksBeforeDrop) implements StreamHandle {
            private $chunks;
            private $i = 0;
            public function __construct(array $chunks) { $this->chunks = $chunks; }
            public function read(): ?string
            {
                if ($this->i < count($this->chunks)) {
                    return $this->chunks[$this->i++];
                }
                throw new StreamDroppedException('connection reset');
            }
            public function close(): void {}
        };
    }

    public function testStreamChatYieldsEventsUntilDone(): void
    {
        $transport = $this->createMock(GaithHttpTransport::class);
        $streamingClient = $this->createMock(StreamingHttpClientInterface::class);
        $streamingClient->expects($this->once())
            ->method('sendStreaming')
            ->willReturn($this->handleFor([
                "event: meta\ndata: " . json_encode(['conversation_id' => 'c1', 'user_message_id' => 'um1', 'stream_id' => 's1']) . "\n\n" .
                "event: token\ndata: " . json_encode(['content' => 'Hi']) . "\n\n" .
                "event: done\ndata: " . json_encode(['message_id' => 'm1', 'conversation_id' => 'c1', 'finish_reason' => 'stop', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]) . "\n\n",
            ]));

        $client = new GaithChatbotClient($transport, $streamingClient);

        $events = iterator_to_array($client->streamChat(GaithUser::for('user-1'), 'hello'));

        $this->assertCount(3, $events);
        $this->assertInstanceOf(MetaEvent::class, $events[0]);
        $this->assertInstanceOf(TokenEvent::class, $events[1]);
        $this->assertInstanceOf(DoneEvent::class, $events[2]);
    }

    public function testUnknownEventNamesAreSkipped(): void
    {
        $transport = $this->createMock(GaithHttpTransport::class);
        $streamingClient = $this->createMock(StreamingHttpClientInterface::class);
        $streamingClient->expects($this->once())
            ->method('sendStreaming')
            ->willReturn($this->handleFor([
                "event: some_future_event\ndata: {}\n\n" .
                "event: done\ndata: " . json_encode(['message_id' => 'm1', 'conversation_id' => 'c1', 'finish_reason' => null, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]]) . "\n\n",
            ]));

        $client = new GaithChatbotClient($transport, $streamingClient);

        $events = iterator_to_array($client->streamChat(GaithUser::for('user-1'), 'hello'));

        $this->assertCount(1, $events);
        $this->assertInstanceOf(DoneEvent::class, $events[0]);
    }

    public function testResumesWithinWindowAfterDrop(): void
    {
        $config = new \Osos\Gaith\Sdk\Config\GaithChatbotConfig(
            'https://gaith-backend-dev.osos.om',
            '11111111-1111-1111-1111-111111111111',
            'test-key'
        );
        $httpFactory = new \GuzzleHttp\Psr7\HttpFactory();
        $dummyHttpClient = $this->createMock(\Psr\Http\Client\ClientInterface::class); // never called by buildStreamingRequest
        $transport = new GaithHttpTransport($config, $dummyHttpClient, $httpFactory, $httpFactory);
        $streamingClient = $this->createMock(StreamingHttpClientInterface::class);

        $metaFrame = "id: 1\nevent: meta\ndata: " . json_encode(['conversation_id' => 'c1', 'user_message_id' => 'um1', 'stream_id' => 's1']) . "\n\n";
        $doneFrame = "event: done\ndata: " . json_encode(['message_id' => 'm1', 'conversation_id' => 'c1', 'finish_reason' => null, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]]) . "\n\n";

        $capturedRequests = [];
        $streamingClient->expects($this->exactly(2))
            ->method('sendStreaming')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedRequests, $metaFrame, $doneFrame) {
                $capturedRequests[] = $request;
                if (count($capturedRequests) === 1) {
                    return $this->droppingHandleThenNull([$metaFrame]);
                }
                return $this->handleFor([$doneFrame]);
            });

        $client = new GaithChatbotClient($transport, $streamingClient);

        $events = iterator_to_array($client->streamChat(GaithUser::for('user-1'), 'hello'));

        $this->assertInstanceOf(MetaEvent::class, $events[0]);
        $this->assertInstanceOf(DoneEvent::class, $events[1]);
        $this->assertSame('s1', $capturedRequests[1]->getHeaderLine('X-Stream-Id'));
        $this->assertNotSame('', $capturedRequests[1]->getHeaderLine('Last-Event-ID'));
    }

    public function testDropBeforeMetaEventPropagatesWithoutResume(): void
    {
        $transport = $this->createMock(GaithHttpTransport::class);
        $streamingClient = $this->createMock(StreamingHttpClientInterface::class);
        $streamingClient->expects($this->once())
            ->method('sendStreaming')
            ->willReturn($this->droppingHandleThenNull([]));

        $client = new GaithChatbotClient($transport, $streamingClient);

        $this->expectException(StreamDroppedException::class);

        iterator_to_array($client->streamChat(GaithUser::for('user-1'), 'hello'));
    }

    public function testCleanStreamEndWithoutTerminalEventStopsWithoutResume(): void
    {
        $transport = $this->createMock(GaithHttpTransport::class);
        $streamingClient = $this->createMock(StreamingHttpClientInterface::class);
        $streamingClient->expects($this->once())
            ->method('sendStreaming')
            ->willReturn($this->handleFor([
                "event: token\ndata: " . json_encode(['content' => 'Hi']) . "\n\n",
            ]));

        $client = new GaithChatbotClient($transport, $streamingClient);

        $events = iterator_to_array($client->streamChat(GaithUser::for('user-1'), 'hello'));

        $this->assertCount(1, $events);
        $this->assertInstanceOf(TokenEvent::class, $events[0]);
    }
}
