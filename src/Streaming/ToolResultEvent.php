<?php

namespace Osos\Gaith\Sdk\Streaming;

final class ToolResultEvent extends ChatEvent
{
    /** @var string */
    private $id;
    /** @var string */
    private $tool;
    /** @var bool */
    private $ok;
    /** @var string|null */
    private $status;

    public function __construct(string $id, string $tool, bool $ok, ?string $status)
    {
        $this->id = $id;
        $this->tool = $tool;
        $this->ok = $ok;
        $this->status = $status;
    }

    public function id(): string { return $this->id; }
    public function tool(): string { return $this->tool; }
    public function ok(): bool { return $this->ok; }
    public function status(): ?string { return $this->status; }
}
