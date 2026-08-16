<?php

namespace Osos\Gaith\Sdk\Streaming;

final class ToolCallEvent extends ChatEvent
{
    /** @var string */
    private $id;
    /** @var string */
    private $tool;

    public function __construct(string $id, string $tool)
    {
        $this->id = $id;
        $this->tool = $tool;
    }

    public function id(): string { return $this->id; }
    public function tool(): string { return $this->tool; }
}
