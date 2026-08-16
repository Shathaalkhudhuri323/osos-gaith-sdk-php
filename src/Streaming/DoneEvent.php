<?php

namespace Osos\Gaith\Sdk\Streaming;

use Osos\Gaith\Sdk\Models\Usage;

final class DoneEvent extends ChatEvent
{
    /** @var string */
    private $messageId;
    /** @var string */
    private $conversationId;
    /** @var string|null */
    private $finishReason;
    /** @var Usage */
    private $usage;

    public function __construct(string $messageId, string $conversationId, ?string $finishReason, Usage $usage)
    {
        $this->messageId = $messageId;
        $this->conversationId = $conversationId;
        $this->finishReason = $finishReason;
        $this->usage = $usage;
    }

    public function isTerminal(): bool { return true; }
    public function messageId(): string { return $this->messageId; }
    public function conversationId(): string { return $this->conversationId; }
    public function finishReason(): ?string { return $this->finishReason; }
    public function usage(): Usage { return $this->usage; }
}
