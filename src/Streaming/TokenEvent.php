<?php

namespace Osos\Gaith\Sdk\Streaming;

final class TokenEvent extends ChatEvent
{
    /** @var string */
    private $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function content(): string { return $this->content; }
}
