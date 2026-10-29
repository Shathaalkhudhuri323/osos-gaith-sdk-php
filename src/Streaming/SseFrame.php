<?php

namespace Osos\Gaith\Sdk\Streaming;

final class SseFrame
{
    /** @var string|null */
    public $id;

    /** @var string|null */
    public $event;

    /** @var string */
    public $data;

    /** @var bool True for a frame that carried only comment lines, such as a heartbeat. */
    public $isComment;

    public function __construct(?string $id, ?string $event, string $data, bool $isComment = false)
    {
        $this->id = $id;
        $this->event = $event;
        $this->data = $data;
        $this->isComment = $isComment;
    }
}
