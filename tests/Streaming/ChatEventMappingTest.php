<?php

namespace Osos\Gaith\Sdk\Tests\Streaming;

use Osos\Gaith\Sdk\Models\FileRef;
use Osos\Gaith\Sdk\Streaming\ChatEvent;
use Osos\Gaith\Sdk\Streaming\ConnectNeededEvent;
use Osos\Gaith\Sdk\Streaming\DoneEvent;
use Osos\Gaith\Sdk\Streaming\FileEvent;
use Osos\Gaith\Sdk\Streaming\MetaEvent;
use Osos\Gaith\Sdk\Streaming\QuestionEvent;
use Osos\Gaith\Sdk\Streaming\SseFrame;
use Osos\Gaith\Sdk\Streaming\TokenEvent;
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

    public function testConnectNeededCarriesTheServiceAndItsLink(): void
    {
        $event = ChatEvent::fromFrame(new SseFrame(null, 'connect_needed', json_encode([
            'server' => 'QHSE',
            'url' => 'https://qhse-mcp.example.com/authorize?state=abc',
        ])));

        $this->assertInstanceOf(ConnectNeededEvent::class, $event);
        $this->assertSame('QHSE', $event->server());
        $this->assertSame('https://qhse-mcp.example.com/authorize?state=abc', $event->url());

        // Without a link there is nothing to show, so it is skipped like an unknown event.
        $this->assertNull(ChatEvent::fromFrame(new SseFrame(null, 'connect_needed', json_encode(['server' => 'QHSE']))));
    }

    public function testQuestionEventCarriesClickableOptions(): void
    {
        // GAITH's present_options tool (AII-3768): a clarifying question with 2-6 labels.
        $frame = new SseFrame(null, 'question', json_encode([
            'id' => 'call_1',
            'question' => 'Which month?',
            'options' => ['October', ' November ', '', 7],
        ]));

        $event = ChatEvent::fromFrame($frame);

        $this->assertInstanceOf(QuestionEvent::class, $event);
        $this->assertSame('call_1', $event->id());
        $this->assertSame('Which month?', $event->question()->question());
        $this->assertSame(['October', 'November'], $event->question()->options()); // non-strings and blanks dropped
        $this->assertFalse($event->isTerminal()); // the turn still ends with `done`
    }

    public function testUnknownEventNameReturnsNull(): void
    {
        $frame = new SseFrame(null, 'some_future_event', '{}');

        $this->assertNull(ChatEvent::fromFrame($frame));
    }
}
