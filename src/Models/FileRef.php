<?php

namespace Osos\Gaith\Sdk\Models;

final class FileRef
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
    private $downloadUrl;

    public function __construct(string $artifactId, string $filename, ?string $mediaType, int $sizeBytes, ?string $downloadUrl)
    {
        $this->artifactId = $artifactId;
        $this->filename = $filename;
        $this->mediaType = $mediaType;
        $this->sizeBytes = $sizeBytes;
        $this->downloadUrl = $downloadUrl;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['artifact_id'],
            (string) ($data['filename'] ?? ''),
            $data['media_type'] ?? null,
            (int) ($data['size_bytes'] ?? 0),
            // Absent on a user's own uploads as stored in message history; the
            // file is still downloadable by artifact id.
            $data['download_url'] ?? null
        );
    }

    public function artifactId(): string { return $this->artifactId; }
    public function filename(): string { return $this->filename; }
    public function mediaType(): ?string { return $this->mediaType; }
    public function sizeBytes(): int { return $this->sizeBytes; }
    /** GAITH-relative URL that needs the API key - never hand it to a browser. Null on user uploads in history. */
    public function downloadUrl(): ?string { return $this->downloadUrl; }
}
