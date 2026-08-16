<?php

namespace Osos\Gaith\Sdk\Tests;

use Osos\Gaith\Sdk\Config\GaithChatbotConfig;
use Osos\Gaith\Sdk\Config\GaithUser;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Models\AttachmentUpload;
use Osos\Gaith\Sdk\Models\ChatbotMeta;
use Osos\Gaith\Sdk\Models\ConversationListOptions;
use Osos\Gaith\Sdk\Models\MessagePageOptions;
use Osos\Gaith\Sdk\Streaming\StreamingHttpClientInterface;
use PHPUnit\Framework\TestCase;

final class GaithChatbotClientTest extends TestCase
{
    private function transportMock(): GaithHttpTransport
    {
        return $this->createMock(GaithHttpTransport::class);
    }

    private function streamingClientMock(): StreamingHttpClientInterface
    {
        return $this->createMock(StreamingHttpClientInterface::class);
    }

    public function testGetMeta(): void
    {
        $transport = $this->transportMock();
        $transport->expects($this->once())
            ->method('get')
            ->with('meta')
            ->willReturn(['name' => 'Bot', 'greeting' => null, 'status' => 'published']);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $meta = $client->getMeta();

        $this->assertInstanceOf(ChatbotMeta::class, $meta);
        $this->assertSame('Bot', $meta->name());
    }

    public function testListConversationsBuildsQueryString(): void
    {
        $transport = $this->transportMock();
        $transport->expects($this->once())
            ->method('get')
            ->with('conversations', 'external_user_id=user-1&limit=10')
            ->willReturn([
                ['id' => 'c1', 'external_user_id' => 'user-1', 'is_test' => false, 'title' => null, 'message_count' => 0, 'last_message_at' => null, 'created_at' => '2026-01-01T00:00:00Z'],
            ]);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $conversations = $client->listConversations(GaithUser::for('user-1'), new ConversationListOptions(null, 10));

        $this->assertCount(1, $conversations);
        $this->assertSame('c1', $conversations[0]->id());
    }

    public function testGetConversation(): void
    {
        $transport = $this->transportMock();
        $transport->expects($this->once())
            ->method('get')
            ->with('conversations/c1', 'external_user_id=user-1')
            ->willReturn(['id' => 'c1', 'external_user_id' => 'user-1', 'is_test' => false, 'title' => null, 'message_count' => 0, 'last_message_at' => null, 'created_at' => '2026-01-01T00:00:00Z']);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $conversation = $client->getConversation(GaithUser::for('user-1'), 'c1');

        $this->assertSame('c1', $conversation->id());
    }

    public function testGetMessages(): void
    {
        $transport = $this->transportMock();
        $transport->expects($this->once())
            ->method('get')
            ->with('conversations/c1/messages', 'external_user_id=user-1&after_seq=5')
            ->willReturn([
                ['id' => 'm1', 'conversation_id' => 'c1', 'role' => 'user', 'content' => 'hi', 'seq' => 6, 'created_at' => '2026-01-01T00:00:00Z', 'metadata' => null],
            ]);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $messages = $client->getMessages(GaithUser::for('user-1'), 'c1', new MessagePageOptions(5));

        $this->assertCount(1, $messages);
        $this->assertSame(6, $messages[0]->seq());
    }

    public function testDeleteConversation(): void
    {
        $transport = $this->transportMock();
        $transport->expects($this->once())
            ->method('delete')
            ->with('conversations/c1', 'external_user_id=user-1');
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $client->deleteConversation(GaithUser::for('user-1'), 'c1');
    }

    public function testUploadAttachmentReadsFileAndPostsMultipart(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gaith');
        file_put_contents($tmp, 'file bytes');

        $transport = $this->transportMock();
        $transport->expects($this->once())
            ->method('postMultipart')
            ->with('attachments', 'file bytes', basename($tmp), 'user-1')
            ->willReturn(['artifact_id' => 'a1', 'filename' => basename($tmp), 'media_type' => null, 'size_bytes' => 10, 'expires_at' => null, 'download_url' => 'https://x.test/a1']);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $upload = $client->uploadAttachment(GaithUser::for('user-1'), $tmp);

        $this->assertInstanceOf(AttachmentUpload::class, $upload);
        $this->assertSame('a1', $upload->artifactId());

        unlink($tmp);
    }

    public function testDownloadAttachment(): void
    {
        $transport = $this->transportMock();
        $stream = \GuzzleHttp\Psr7\Utils::streamFor('raw bytes');
        $transport->expects($this->once())
            ->method('getRaw')
            ->with('attachments/a1', 'external_user_id=user-1')
            ->willReturn($stream);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $result = $client->downloadAttachment(GaithUser::for('user-1'), 'a1');

        $this->assertSame('raw bytes', (string) $result);
    }
}
