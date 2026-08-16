<?php

namespace Osos\Gaith\Sdk\Streaming;

final class MetaEvent extends ChatEvent
{
    /** @var string */
    private $conversationId;
    /** @var string */
    private $userMessageId;
    /** @var string */
    private $streamId;

    public function __construct(string $conversationId, string $userMessageId, string $streamId)
    {
        $this->conversationId = $conversationId;
        $this->userMessageId = $userMessageId;
        $this->streamId = $streamId;
    }

    public function conversationId(): string { return $this->conversationId; }
    public function userMessageId(): string { return $this->userMessageId; }
    public function streamId(): string { return $this->streamId; }
}
