<?php

namespace Osos\Gaith\Sdk\Models;

final class ChatbotMeta
{
    /** @var string */
    private $name;
    /** @var string|null */
    private $greeting;
    /** @var string */
    private $status;

    public function __construct(string $name, ?string $greeting, string $status)
    {
        $this->name = $name;
        $this->greeting = $greeting;
        $this->status = $status;
    }

    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['greeting'] ?? null, $data['status']);
    }

    public function name(): string { return $this->name; }
    public function greeting(): ?string { return $this->greeting; }
    public function status(): string { return $this->status; }
}
