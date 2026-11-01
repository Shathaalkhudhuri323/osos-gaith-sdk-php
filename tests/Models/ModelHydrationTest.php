<?php

namespace Osos\Gaith\Sdk\Tests\Models;

use Osos\Gaith\Sdk\Models\ChatMessage;
use Osos\Gaith\Sdk\Models\FileRef;
use Osos\Gaith\Sdk\Models\MessageMetadata;
use PHPUnit\Framework\TestCase;

final class ModelHydrationTest extends TestCase
{
    public function testUserUploadRefsWithoutDownloadUrlHydrate(): void
    {
        // GAITH stores a user's uploaded attachments on the message without a
        // download_url (chatbot/attachments.py resolve_input_refs); getMessages()
        // must not crash on any conversation where the user attached a file.
        $metadata = MessageMetadata::fromArray(['attachments' => [[
            'artifact_id' => 'a1',
            'filename' => 'photo.jpg',
            'media_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ]]]);

        $ref = $metadata->attachments()[0];
        $this->assertSame('photo.jpg', $ref->filename());
        $this->assertNull($ref->downloadUrl());
        $this->assertSame([], $metadata->files());
    }

    public function testMetadataExposesTheClarifyingQuestionGaithSaved(): void
    {
        // As GAITH persists it on the assistant message: tool_events plus finish_reason.
        $metadata = MessageMetadata::fromArray([
            'finish_reason' => 'question',
            'tool_events' => [
                ['event' => 'tool_call', 'id' => 'c0', 'tool' => 'lookup'],
                ['event' => 'question', 'id' => 'c1', 'question' => 'Which month?', 'options' => ['October', 'November']],
            ],
        ]);

        $this->assertSame('Which month?', $metadata->question()->question());
        $this->assertSame(['October', 'November'], $metadata->question()->options());
        $this->assertNull(MessageMetadata::fromArray(['files' => []])->question());
    }

    public function testMessageMetadataFromArrayWithAttachmentsAndFiles(): void
    {
        $fileData = [
            'artifact_id' => 'a1',
            'filename' => 'f.txt',
            'media_type' => null,
            'size_bytes' => 5,
            'download_url' => 'https://example.test/a1',
        ];
        $metadata = MessageMetadata::fromArray(['attachments' => [$fileData], 'files' => [$fileData]]);

        $this->assertCount(1, $metadata->attachments());
        $this->assertInstanceOf(FileRef::class, $metadata->attachments()[0]);
        $this->assertCount(1, $metadata->files());
    }

    public function testChatMessageFromArrayWithMetadata(): void
    {
        $message = ChatMessage::fromArray([
            'id' => 'm1',
            'conversation_id' => 'c1',
            'role' => 'assistant',
            'content' => 'Hi there',
            'seq' => 2,
            'created_at' => '2026-08-01T00:00:00Z',
            'metadata' => ['attachments' => [], 'files' => []],
        ]);

        $this->assertInstanceOf(MessageMetadata::class, $message->metadata());
    }
}
