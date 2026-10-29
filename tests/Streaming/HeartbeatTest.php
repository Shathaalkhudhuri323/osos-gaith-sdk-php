<?php

namespace Osos\Gaith\Sdk\Tests\Streaming;

use Osos\Gaith\Sdk\Config\GaithUser;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Streaming\HeartbeatEvent;
use Osos\Gaith\Sdk\Streaming\SseReader;
use Osos\Gaith\Sdk\Streaming\StreamHandle;
use Osos\Gaith\Sdk\Streaming\StreamingHttpClientInterface;
use PHPUnit\Framework\TestCase;

/**
 * The server sends an SSE comment every 15s of silence so the connection is not
 * mistaken for dead. A consumer proxying the stream onward has to be able to see
 * those, or its own downstream connection goes quiet and gets culled by a proxy
 * while the upstream is perfectly healthy.
 */
final class HeartbeatTest extends TestCase
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

    public function testCommentOnlyFrameIsSurfacedAsAComment(): void
    {
        $handle = $this->handleFor([":heartbeat\n\nevent: token\ndata: {\"content\":\"hi\"}\n\n"]);

        $frames = iterator_to_array((new SseReader())->read($handle), false);

        $this->assertCount(2, $frames);
        $this->assertTrue($frames[0]->isComment);
        $this->assertNull($frames[0]->event);
        $this->assertFalse($frames[1]->isComment);
        $this->assertSame('token', $frames[1]->event);
    }

    public function testStreamChatYieldsHeartbeatsWithoutEndingTheTurn(): void
    {
        $transport = $this->createStub(GaithHttpTransport::class);
        $streaming = $this->createStub(StreamingHttpClientInterface::class);
        $streaming->method('sendStreaming')->willReturn($this->handleFor([
            ":heartbeat\n\n",
            "event: token\ndata: {\"content\":\"hi\"}\n\n",
            ":heartbeat\n\n",
            "event: done\ndata: {\"message_id\":\"m1\",\"conversation_id\":\"c1\",\"finish_reason\":\"stop\",\"usage\":{\"input_tokens\":1,\"output_tokens\":1}}\n\n",
        ]));

        $client = new GaithChatbotClient($transport, $streaming);
        $events = iterator_to_array($client->streamChat(GaithUser::for('u1'), 'hello'), false);

        $heartbeats = array_values(array_filter($events, static function ($e) {
            return $e instanceof HeartbeatEvent;
        }));

        $this->assertCount(2, $heartbeats);
        $this->assertFalse($heartbeats[0]->isTerminal());
        // The turn still ends on done, and heartbeats did not disturb the order.
        $this->assertTrue($events[count($events) - 1]->isTerminal());
    }
}
