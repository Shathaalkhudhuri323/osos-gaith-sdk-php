<?php

namespace Osos\Gaith\Sdk\Models;

final class ChatMessage
{
    /** @var string */
    private $id;
    /** @var string */
    private $conversationId;
    /** @var string */
    private $role;
    /** @var string */
    private $content;
    /** @var int */
    private $seq;
    /** @var string */
    private $createdAt;
    /** @var MessageMetadata|null */
    private $metadata;

    public function __construct(string $id, string $conversationId, string $role, string $content, int $seq, string $createdAt, ?MessageMetadata $metadata)
    {
        $this->id = $id;
        $this->conversationId = $conversationId;
        $this->role = $role;
        $this->content = $content;
        $this->seq = $seq;
        $this->createdAt = $createdAt;
        $this->metadata = $metadata;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['conversation_id'],
            $data['role'],
            $data['content'],
            (int) $data['seq'],
            $data['created_at'],
            isset($data['metadata']) && $data['metadata'] !== null ? MessageMetadata::fromArray($data['metadata']) : null
        );
    }

    public function id(): string { return $this->id; }
    public function conversationId(): string { return $this->conversationId; }
    public function role(): string { return $this->role; }
    public function content(): string { return $this->content; }
    public function seq(): int { return $this->seq; }
    public function createdAt(): string { return $this->createdAt; }
    public function metadata(): ?MessageMetadata { return $this->metadata; }
}
