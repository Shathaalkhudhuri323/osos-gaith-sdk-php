<?php

namespace Osos\Gaith\Sdk\Streaming;

final class ErrorEvent extends ChatEvent
{
    /** @var string */
    private $code;
    /** @var string */
    private $message;

    public function __construct(string $code, string $message)
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function isTerminal(): bool { return true; }
    public function code(): string { return $this->code; }
    public function message(): string { return $this->message; }
}
