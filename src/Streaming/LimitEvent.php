<?php

namespace Osos\Gaith\Sdk\Streaming;

final class LimitEvent extends ChatEvent
{
    /** @var string */
    private $reason;

    public function __construct(string $reason)
    {
        $this->reason = $reason;
    }

    public function reason(): string { return $this->reason; }
}
