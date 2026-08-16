<?php

namespace Osos\Gaith\Sdk\Models;

final class AttachmentUpload
{
    /** @var string */
    private $artifactId;
    /** @var string */
    private $filename;
    /** @var string|null */
    private $mediaType;
    /** @var int */
    private $sizeBytes;
    /** @var string|null */
    private $expiresAt;
    /** @var string */
    private $downloadUrl;

    public function __construct(string $artifactId, string $filename, ?string $mediaType, int $sizeBytes, ?string $expiresAt, string $downloadUrl)
    {
        $this->artifactId = $artifactId;
        $this->filename = $filename;
        $this->mediaType = $mediaType;
        $this->sizeBytes = $sizeBytes;
        $this->expiresAt = $expiresAt;
        $this->downloadUrl = $downloadUrl;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['artifact_id'],
            $data['filename'],
            $data['media_type'] ?? null,
            (int) $data['size_bytes'],
            $data['expires_at'] ?? null,
            $data['download_url']
        );
    }

    public function artifactId(): string { return $this->artifactId; }
    public function filename(): string { return $this->filename; }
    public function mediaType(): ?string { return $this->mediaType; }
    public function sizeBytes(): int { return $this->sizeBytes; }
    public function expiresAt(): ?string { return $this->expiresAt; }
    public function downloadUrl(): string { return $this->downloadUrl; }
}
