<?php

namespace Osos\Gaith\Sdk\Models;

final class MessageMetadata
{
    /** @var FileRef[] */
    private $attachments;
    /** @var FileRef[] */
    private $files;
    /** @var Question|null */
    private $question;

    /**
     * @param FileRef[] $attachments
     * @param FileRef[] $files
     */
    public function __construct(array $attachments, array $files, ?Question $question = null)
    {
        $this->attachments = $attachments;
        $this->files = $files;
        $this->question = $question;
    }

    public static function fromArray(array $data): self
    {
        $mapRefs = static function (array $items): array {
            return array_map(static function (array $item): FileRef {
                return FileRef::fromArray($item);
            }, $items);
        };

        // GAITH saves a clarifying question among the reply's tool events.
        $question = null;
        foreach ((array) ($data['tool_events'] ?? []) as $event) {
            if (is_array($event) && ($event['event'] ?? null) === 'question') {
                $question = Question::fromArray($event) ?? $question;
            }
        }

        return new self(
            $mapRefs($data['attachments'] ?? []),
            $mapRefs($data['files'] ?? []),
            $question
        );
    }

    /** @return FileRef[] */
    public function attachments(): array { return $this->attachments; }

    /** @return FileRef[] */
    public function files(): array { return $this->files; }

    /** The clarifying question this reply asked, with its options; null if it asked none. */
    public function question(): ?Question { return $this->question; }
}
