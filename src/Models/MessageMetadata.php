<?php

namespace Osos\Gaith\Sdk\Models;

final class MessageMetadata
{
    /** @var FileRef[] */
    private $attachments;
    /** @var FileRef[] */
    private $files;

    /**
     * @param FileRef[] $attachments
     * @param FileRef[] $files
     */
    public function __construct(array $attachments, array $files)
    {
        $this->attachments = $attachments;
        $this->files = $files;
    }

    public static function fromArray(array $data): self
    {
        $mapRefs = static function (array $items): array {
            return array_map(static function (array $item): FileRef {
                return FileRef::fromArray($item);
            }, $items);
        };

        return new self(
            $mapRefs($data['attachments'] ?? []),
            $mapRefs($data['files'] ?? [])
        );
    }

    /** @return FileRef[] */
    public function attachments(): array { return $this->attachments; }

    /** @return FileRef[] */
    public function files(): array { return $this->files; }
}
