<?php

namespace Osos\Gaith\Sdk\Models;

final class MessagePageOptions
{
    /** @var int|null */
    private $afterSeq;
    /** @var int|null */
    private $limit;

    public function __construct(?int $afterSeq = null, ?int $limit = null)
    {
        $this->afterSeq = $afterSeq;
        $this->limit = $limit;
    }

    public function afterSeq(): ?int { return $this->afterSeq; }
    public function limit(): ?int { return $this->limit; }
}
