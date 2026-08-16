<?php

namespace Osos\Gaith\Sdk\Tests\Streaming;

use Osos\Gaith\Sdk\Models\FileRef;
use Osos\Gaith\Sdk\Streaming\ChatEvent;
use Osos\Gaith\Sdk\Streaming\DoneEvent;
use Osos\Gaith\Sdk\Streaming\ErrorEvent;
use Osos\Gaith\Sdk\Streaming\FileEvent;
use Osos\Gaith\Sdk\Streaming\LimitEvent;
use Osos\Gaith\Sdk\Streaming\MetaEvent;
use Osos\Gaith\Sdk\Streaming\SafetyBlockEvent;
use Osos\Gaith\Sdk\Streaming\SseFrame;
use Osos\Gaith\Sdk\Streaming\TokenEvent;
use Osos\Gaith\Sdk\Streaming\ToolCallEvent;
use Osos\Gaith\Sdk\Streaming\ToolResultEvent;
use PHPUnit\Framework\TestCase;

final class ChatEventMappingTest extends TestCase
{
    public function testMetaEvent(): void
    {
        $frame = new SseFrame('1', 'meta', json_encode([
            'conversation_id' => 'c1', 'user_message_id' => 'um1', 'stream_id' => 's1',
        ]));

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(MetaEvent::class, $event);
        $this->assertSame('c1', $event->conversationId());
        $this->assertSame('um1', $event->userMessageId());
        $this->assertSame('s1', $event->streamId());
        $this->assertFalse($event->isTerminal());
    }

    public function testTokenEvent(): void
    {
        $frame = new SseFrame(null, 'token', json_encode(['content' => 'Hi']));

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(TokenEvent::class, $event);
        $this->assertSame('Hi', $event->content());
    }

    public function testToolCallEvent(): void
    {
        $frame = new SseFrame(null, 'tool_call', json_encode(['id' => 't1', 'tool' => 'search']));

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(ToolCallEvent::class, $event);
        $this->assertSame('t1', $event->id());
        $this->assertSame('search', $event->tool());
    }

    public function testToolResultEvent(): void
    {
        $frame = new SseFrame(null, 'tool_result', json_encode([
            'id' => 't1', 'tool' => 'search', 'ok' => true, 'status' => 'done',
        ]));

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(ToolResultEvent::class, $event);
        $this->assertTrue($event->ok());
        $this->assertSame('done', $event->status());
    }

    public function testFileEvent(): void
    {
        $frame = new SseFrame(null, 'file', json_encode([
            'artifact_id' => 'a1', 'filename' => 'f.txt', 'media_type' => null,
            'size_bytes' => 5, 'download_url' => 'https://x.test/a1',
        ]));

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(FileEvent::class, $event);
        $this->assertInstanceOf(FileRef::class, $event->file());
        $this->assertSame('a1', $event->file()->artifactId());
    }

    public function testLimitEvent(): void
    {
        $frame = new SseFrame(null, 'limit', json_encode(['reason' => 'max_steps']));

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(LimitEvent::class, $event);
        $this->assertSame('max_steps', $event->reason());
    }

    public function testDoneEventIsTerminal(): void
    {
        $frame = new SseFrame(null, 'done', json_encode([
            'message_id' => 'm1', 'conversation_id' => 'c1', 'finish_reason' => 'stop',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
        ]));

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(DoneEvent::class, $event);
        $this->assertTrue($event->isTerminal());
        $this->assertSame(1, $event->usage()->inputTokens());
    }

    public function testErrorEventIsTerminal(): void
    {
        $frame = new SseFrame(null, 'error', json_encode(['code' => 'rate_limited', 'message' => 'slow down']));

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(ErrorEvent::class, $event);
        $this->assertTrue($event->isTerminal());
        $this->assertSame('rate_limited', $event->code());
    }

    public function testSafetyBlockEventIsTerminal(): void
    {
        $frame = new SseFrame(null, 'safety_block', '{}');

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(SafetyBlockEvent::class, $event);
        $this->assertTrue($event->isTerminal());
    }

    public function testUnknownEventNameReturnsNull(): void
    {
        $frame = new SseFrame(null, 'some_future_event', '{}');

        $this->assertNull(ChatEvent::fromFrame($frame));
    }

    public function testNullEventNameReturnsNull(): void
    {
        $frame = new SseFrame(null, null, '{}');

        $this->assertNull(ChatEvent::fromFrame($frame));
    }
}
