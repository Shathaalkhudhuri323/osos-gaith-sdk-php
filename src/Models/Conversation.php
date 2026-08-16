<?php

namespace Osos\Gaith\Sdk\Models;

final class Conversation
{
    /** @var string */
    private $id;
    /** @var string */
    private $externalUserId;
    /** @var bool */
    private $isTest;
    /** @var string|null */
    private $title;
    /** @var int */
    private $messageCount;
    /** @var string|null */
    private $lastMessageAt;
    /** @var string */
    private $createdAt;

    public function __construct(string $id, string $externalUserId, bool $isTest, ?string $title, int $messageCount, ?string $lastMessageAt, string $createdAt)
    {
        $this->id = $id;
        $this->externalUserId = $externalUserId;
        $this->isTest = $isTest;
        $this->title = $title;
        $this->messageCount = $messageCount;
        $this->lastMessageAt = $lastMessageAt;
        $this->createdAt = $createdAt;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['external_user_id'],
            (bool) $data['is_test'],
            $data['title'] ?? null,
            (int) $data['message_count'],
            $data['last_message_at'] ?? null,
            $data['created_at']
        );
    }

    public function id(): string { return $this->id; }
    public function externalUserId(): string { return $this->externalUserId; }
    public function isTest(): bool { return $this->isTest; }
    public function title(): ?string { return $this->title; }
    public function messageCount(): int { return $this->messageCount; }
    public function lastMessageAt(): ?string { return $this->lastMessageAt; }
    public function createdAt(): string { return $this->createdAt; }
}
