<?php

namespace Osos\Gaith\Sdk\Tests\Streaming;

use Osos\Gaith\Sdk\Streaming\SseReader;
use Osos\Gaith\Sdk\Streaming\StreamHandle;
use PHPUnit\Framework\TestCase;

final class SseReaderTest extends TestCase
{
    private function handleFor(array $chunks): StreamHandle
    {
        return new class ($chunks) implements StreamHandle {
            private $chunks;
            private $i = 0;

            public function __construct(array $chunks) { $this->chunks = $chunks; }

            public function read(): ?string
            {
                return $this->chunks[$this->i++] ?? null;
            }

            public function close(): void {}
        };
    }

    public function testParsesSingleFrame(): void
    {
        $handle = $this->handleFor(["id: 1\nevent: meta\ndata: {\"a\":1}\n\n"]);
        $reader = new SseReader();

        $frames = iterator_to_array($reader->read($handle));

        $this->assertCount(1, $frames);
        $this->assertSame('1', $frames[0]->id);
        $this->assertSame('meta', $frames[0]->event);
        $this->assertSame('{"a":1}', $frames[0]->data);
    }

    public function testParsesMultipleFramesInOneChunk(): void
    {
        $handle = $this->handleFor([
            "event: token\ndata: {\"content\":\"Hi\"}\n\nevent: token\ndata: {\"content\":\" there\"}\n\n",
        ]);
        $reader = new SseReader();

        $frames = iterator_to_array($reader->read($handle));

        $this->assertCount(2, $frames);
        $this->assertSame('{"content":"Hi"}', $frames[0]->data);
        $this->assertSame('{"content":" there"}', $frames[1]->data);
    }

    public function testFrameSplitAcrossChunkBoundary(): void
    {
        $handle = $this->handleFor(["event: to", "ken\ndata: {\"content\":\"x\"}\n\n"]);
        $reader = new SseReader();

        $frames = iterator_to_array($reader->read($handle));

        $this->assertCount(1, $frames);
        $this->assertSame('token', $frames[0]->event);
    }

    public function testMultipleDataLinesJoinedWithNewline(): void
    {
        $handle = $this->handleFor(["event: done\ndata: line1\ndata: line2\n\n"]);
        $reader = new SseReader();

        $frames = iterator_to_array($reader->read($handle));

        $this->assertSame("line1\nline2", $frames[0]->data);
    }

    public function testCommentLinesAreIgnored(): void
    {
        $handle = $this->handleFor([":heartbeat\n\nevent: done\ndata: {}\n\n"]);
        $reader = new SseReader();

        $frames = iterator_to_array($reader->read($handle));

        $this->assertCount(1, $frames);
        $this->assertSame('done', $frames[0]->event);
    }

    public function testFrameWithoutIdHasNullId(): void
    {
        $handle = $this->handleFor(["event: token\ndata: {}\n\n"]);
        $reader = new SseReader();

        $frames = iterator_to_array($reader->read($handle));

        $this->assertNull($frames[0]->id);
    }

    public function testEmptyStreamYieldsNoFrames(): void
    {
        $handle = $this->handleFor([]);
        $reader = new SseReader();

        $frames = iterator_to_array($reader->read($handle));

        $this->assertCount(0, $frames);
    }

    public function testTrailingIncompleteFrameWithoutBlankLineIsDropped(): void
    {
        $handle = $this->handleFor(["event: done\ndata: {}\n\nevent: partial\ndata: no-terminator"]);
        $reader = new SseReader();

        $frames = iterator_to_array($reader->read($handle));

        $this->assertCount(1, $frames);
        $this->assertSame('done', $frames[0]->event);
    }
}
