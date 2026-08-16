<?php

namespace Osos\Gaith\Sdk\Models;

final class ConversationListOptions
{
    /** @var bool|null */
    private $isTest;
    /** @var int|null */
    private $limit;
    /** @var string|null */
    private $before;
    /** @var int|null */
    private $offset;

    public function __construct(?bool $isTest = null, ?int $limit = null, ?string $before = null, ?int $offset = null)
    {
        $this->isTest = $isTest;
        $this->limit = $limit;
        $this->before = $before;
        $this->offset = $offset;
    }

    public function isTest(): ?bool { return $this->isTest; }
    public function limit(): ?int { return $this->limit; }
    public function before(): ?string { return $this->before; }
    public function offset(): ?int { return $this->offset; }
}
