<?php

namespace Osos\Gaith\Sdk;

use Osos\Gaith\Sdk\Config\GaithUser;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Http\QueryBuilder;
use Osos\Gaith\Sdk\Models\AttachmentUpload;
use Osos\Gaith\Sdk\Models\ChatbotMeta;
use Osos\Gaith\Sdk\Models\ChatMessage;
use Osos\Gaith\Sdk\Models\Conversation;
use Osos\Gaith\Sdk\Models\ConversationListOptions;
use Osos\Gaith\Sdk\Models\MessagePageOptions;
use Osos\Gaith\Sdk\Streaming\ChatEvent;
use Osos\Gaith\Sdk\Streaming\SseReader;
use Osos\Gaith\Sdk\Streaming\StreamDroppedException;
use Osos\Gaith\Sdk\Streaming\StreamingHttpClientInterface;
use Psr\Http\Message\RequestInterface;

final class GaithChatbotClient
{
    private const RESUME_WINDOW_SECONDS = 60;

    /** @var GaithHttpTransport */
    private $transport;

    /** @var StreamingHttpClientInterface */
    private $streamingClient;

    /** @var SseReader */
    private $sseReader;

    public function __construct(GaithHttpTransport $transport, StreamingHttpClientInterface $streamingClient)
    {
        $this->transport = $transport;
        $this->streamingClient = $streamingClient;
        $this->sseReader = new SseReader();
    }

    public function getMeta(): ChatbotMeta
    {
        return ChatbotMeta::fromArray($this->transport->get('meta'));
    }

    /**
     * @return Conversation[]
     */
    public function listConversations(GaithUser $user, ConversationListOptions $options): array
    {
        $query = (new QueryBuilder())
            ->add('external_user_id', $user->id())
            ->add('is_test', $options->isTest())
            ->add('limit', $options->limit())
            ->add('before', $options->before())
            ->add('offset', $options->offset())
            ->toString();

        $rows = $this->transport->get('conversations', $query);

        return array_map(static function (array $row): Conversation {
            return Conversation::fromArray($row);
        }, $rows);
    }

    public function getConversation(GaithUser $user, string $conversationId): Conversation
    {
        $query = (new QueryBuilder())->add('external_user_id', $user->id())->toString();

        return Conversation::fromArray($this->transport->get('conversations/' . $conversationId, $query));
    }

    /**
     * @return ChatMessage[]
     */
    public function getMessages(GaithUser $user, string $conversationId, MessagePageOptions $options): array
    {
        $query = (new QueryBuilder())
            ->add('external_user_id', $user->id())
            ->add('after_seq', $options->afterSeq())
            ->add('limit', $options->limit())
            ->toString();

        $rows = $this->transport->get('conversations/' . $conversationId . '/messages', $query);

        return array_map(static function (array $row): ChatMessage {
            return ChatMessage::fromArray($row);
        }, $rows);
    }

    public function deleteConversation(GaithUser $user, string $conversationId): void
    {
        $query = (new QueryBuilder())->add('external_user_id', $user->id())->toString();

        $this->transport->delete('conversations/' . $conversationId, $query);
    }

    public function uploadAttachment(GaithUser $user, string $filePath, ?string $filename = null): AttachmentUpload
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new \InvalidArgumentException('Unable to read file: ' . $filePath);
        }

        $result = $this->transport->postMultipart(
            'attachments',
            $contents,
            $filename ?? basename($filePath),
            $user->id()
        );

        return AttachmentUpload::fromArray($result);
    }

    public function downloadAttachment(GaithUser $user, string $artifactId): \Psr\Http\Message\StreamInterface
    {
        $query = (new QueryBuilder())->add('external_user_id', $user->id())->toString();

        return $this->transport->getRaw('attachments/' . $artifactId, $query);
    }

    /**
     * @param array<string, mixed> $options May contain: conversation_id, conversation_key, metadata, attachments
     * @return \Generator<ChatEvent>
     */
    public function streamChat(GaithUser $user, string $message, array $options = []): \Generator
    {
        $lastEventId = null;
        $streamId = null;
        $startedAt = null;

        while (true) {
            $request = $this->buildChatRequest($user, $message, $options, $lastEventId, $streamId);
            $handle = $this->streamingClient->sendStreaming($request);

            try {
                foreach ($this->sseReader->read($handle) as $frame) {
                    if ($frame->id !== null) {
                        $lastEventId = $frame->id;
                    }

                    $event = ChatEvent::fromFrame($frame);
                    if ($event === null) {
                        continue;
                    }

                    if ($event instanceof \Osos\Gaith\Sdk\Streaming\MetaEvent) {
                        $streamId = $event->streamId();
                        $startedAt = $startedAt ?? time();
                    }

                    yield $event;

                    if ($event->isTerminal()) {
                        return;
                    }
                }

                return;
            } catch (StreamDroppedException $e) {
                $handle->close();

                $withinWindow = $streamId !== null
                    && $startedAt !== null
                    && (time() - $startedAt) < self::RESUME_WINDOW_SECONDS;

                if (!$withinWindow) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildChatRequest(GaithUser $user, string $message, array $options, ?string $lastEventId, ?string $streamId): RequestInterface
    {
        $body = array_merge([
            'message' => $message,
            'external_user_id' => $user->id(),
        ], $options);

        $headers = [];
        if ($lastEventId !== null && $streamId !== null) {
            $headers['Last-Event-ID'] = $lastEventId;
            $headers['X-Stream-Id'] = $streamId;
        }

        return $this->transport->buildStreamingRequest('chat', $body, $headers);
    }
}
