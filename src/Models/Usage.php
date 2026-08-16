<?php

namespace Osos\Gaith\Sdk\Models;

final class Usage
{
    /** @var int */
    private $inputTokens;
    /** @var int */
    private $outputTokens;

    public function __construct(int $inputTokens, int $outputTokens)
    {
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['input_tokens'], (int) $data['output_tokens']);
    }

    public function inputTokens(): int { return $this->inputTokens; }
    public function outputTokens(): int { return $this->outputTokens; }
}
