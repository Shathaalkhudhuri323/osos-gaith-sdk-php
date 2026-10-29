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

    public function testUploadAttachmentPassesNameAndMediaType(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gaith');
        file_put_contents($tmp, 'jpeg bytes');
        $transport = $this->transportMock();
        $transport->expects($this->once())
            ->method('postMultipart')
            ->with('attachments', 'jpeg bytes', 'site photo.jpg', 'user-1', 'image/jpeg')
            ->willReturn(['artifact_id' => 'a1', 'filename' => 'site photo.jpg', 'media_type' => 'image/jpeg', 'size_bytes' => 10, 'expires_at' => null, 'download_url' => '/x']);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $upload = $client->uploadAttachment(GaithUser::for('user-1'), $tmp, 'site photo.jpg', 'image/jpeg');

        $this->assertSame('image/jpeg', $upload->mediaType());
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

    /**
     * A proxy serving the file to a browser needs its type and name, not only bytes.
     */
    public function testDownloadAttachmentFileReturnsTypeAndNameFromTheResponse(): void
    {
        $captured = null;
        $transport = $this->realTransport(new \GuzzleHttp\Psr7\Response(200, [
            'Content-Type' => 'application/pdf; charset=binary',
            'Content-Disposition' => "attachment; filename=\"report.pdf\"; filename*=UTF-8''r%C3%A9port.pdf",
            'Content-Length' => '4',
        ], '%PDF'), $captured);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $file = $client->downloadAttachmentFile(GaithUser::for('user-1'), 'a1');

        $this->assertSame('application/pdf', $file->mediaType());
        $this->assertSame('réport.pdf', $file->filename()); // RFC 5987 form wins over the ASCII fallback
        $this->assertSame(4, $file->sizeBytes());
        $this->assertSame('%PDF', (string) $file->stream());
        $this->assertSame('/api/v1/chatbots/11111111-1111-1111-1111-111111111111/attachments/a1', $captured->getUri()->getPath());
        $this->assertSame('external_user_id=user-1', $captured->getUri()->getQuery());
    }

    public function testDownloadAttachmentFileToleratesMissingHeaders(): void
    {
        $captured = null;
        $transport = $this->realTransport(new \GuzzleHttp\Psr7\Response(200, [], 'bytes'), $captured);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $file = $client->downloadAttachmentFile(GaithUser::for('user-1'), 'a1');

        $this->assertNull($file->mediaType());
        $this->assertNull($file->filename());
        $this->assertSame('bytes', (string) $file->stream());
    }

    public function testDownloadAttachmentFileMapsAnExpiredFileToGone(): void
    {
        $captured = null;
        $transport = $this->realTransport(new \GuzzleHttp\Psr7\Response(410, [], '{"detail":"Artifact has expired."}'), $captured);
        $client = new GaithChatbotClient($transport, $this->streamingClientMock());

        $this->expectException(\Osos\Gaith\Sdk\Exceptions\GaithGoneException::class);

        $client->downloadAttachmentFile(GaithUser::for('user-1'), 'a1');
    }

    private function realTransport(\GuzzleHttp\Psr7\Response $response, ?\Psr\Http\Message\RequestInterface &$captured): GaithHttpTransport
    {
        $httpClient = new class ($response, $captured) implements \Psr\Http\Client\ClientInterface {
            private $response;
            private $captured;
            public function __construct($response, &$captured) { $this->response = $response; $this->captured = &$captured; }
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->captured = $request;
                return $this->response;
            }
        };
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $config = new GaithChatbotConfig('https://gaith-backend-dev.osos.om', '11111111-1111-1111-1111-111111111111', 'test-key');

        return new GaithHttpTransport($config, $httpClient, $factory, $factory);
    }
}
