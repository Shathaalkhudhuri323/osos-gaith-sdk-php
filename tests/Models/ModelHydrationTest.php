<?php

namespace Osos\Gaith\Sdk\Tests\Models;

use Osos\Gaith\Sdk\Models\AttachmentUpload;
use Osos\Gaith\Sdk\Models\ChatbotMeta;
use Osos\Gaith\Sdk\Models\ChatMessage;
use Osos\Gaith\Sdk\Models\Conversation;
use Osos\Gaith\Sdk\Models\FileRef;
use Osos\Gaith\Sdk\Models\MessageMetadata;
use Osos\Gaith\Sdk\Models\Usage;
use PHPUnit\Framework\TestCase;

final class ModelHydrationTest extends TestCase
{
    public function testChatbotMetaFromArray(): void
    {
        $meta = ChatbotMeta::fromArray(['name' => 'HR Bot', 'greeting' => 'Hi!', 'status' => 'published']);

        $this->assertSame('HR Bot', $meta->name());
        $this->assertSame('Hi!', $meta->greeting());
        $this->assertSame('published', $meta->status());
    }

    public function testChatbotMetaGreetingCanBeNull(): void
    {
        $meta = ChatbotMeta::fromArray(['name' => 'HR Bot', 'greeting' => null, 'status' => 'published']);

        $this->assertNull($meta->greeting());
    }

    public function testUsageFromArray(): void
    {
        $usage = Usage::fromArray(['input_tokens' => 10, 'output_tokens' => 20]);

        $this->assertSame(10, $usage->inputTokens());
        $this->assertSame(20, $usage->outputTokens());
    }

    public function testFileRefFromArray(): void
    {
        $file = FileRef::fromArray([
            'artifact_id' => 'a1',
            'filename' => 'report.pdf',
            'media_type' => 'application/pdf',
            'size_bytes' => 1024,
            'download_url' => 'https://example.test/a1',
        ]);

        $this->assertSame('a1', $file->artifactId());
        $this->assertSame('report.pdf', $file->filename());
        $this->assertSame('application/pdf', $file->mediaType());
        $this->assertSame(1024, $file->sizeBytes());
        $this->assertSame('https://example.test/a1', $file->downloadUrl());
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

    public function testConversationFromArray(): void
    {
        $conversation = Conversation::fromArray([
            'id' => 'c1',
            'external_user_id' => 'user-1',
            'is_test' => false,
            'title' => 'Support',
            'message_count' => 3,
            'last_message_at' => '2026-08-01T00:00:00Z',
            'created_at' => '2026-07-01T00:00:00Z',
        ]);

        $this->assertSame('c1', $conversation->id());
        $this->assertSame('user-1', $conversation->externalUserId());
        $this->assertFalse($conversation->isTest());
        $this->assertSame('Support', $conversation->title());
        $this->assertSame(3, $conversation->messageCount());
        $this->assertSame('2026-08-01T00:00:00Z', $conversation->lastMessageAt());
        $this->assertSame('2026-07-01T00:00:00Z', $conversation->createdAt());
    }

    public function testChatMessageFromArrayWithoutMetadata(): void
    {
        $message = ChatMessage::fromArray([
            'id' => 'm1',
            'conversation_id' => 'c1',
            'role' => 'user',
            'content' => 'Hello',
            'seq' => 1,
            'created_at' => '2026-08-01T00:00:00Z',
            'metadata' => null,
        ]);

        $this->assertSame('m1', $message->id());
        $this->assertSame('user', $message->role());
        $this->assertNull($message->metadata());
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

    public function testAttachmentUploadFromArray(): void
    {
        $upload = AttachmentUpload::fromArray([
            'artifact_id' => 'a1',
            'filename' => 'x.txt',
            'media_type' => 'text/plain',
            'size_bytes' => 3,
            'expires_at' => '2026-09-01T00:00:00Z',
            'download_url' => 'https://example.test/a1',
        ]);

        $this->assertSame('a1', $upload->artifactId());
        $this->assertSame('2026-09-01T00:00:00Z', $upload->expiresAt());
    }

    public function testAttachmentUploadExpiresAtCanBeNull(): void
    {
        $upload = AttachmentUpload::fromArray([
            'artifact_id' => 'a1',
            'filename' => 'x.txt',
            'media_type' => null,
            'size_bytes' => 3,
            'expires_at' => null,
            'download_url' => 'https://example.test/a1',
        ]);

        $this->assertNull($upload->expiresAt());
    }
}
