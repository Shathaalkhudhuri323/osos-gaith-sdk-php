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

    public function __construct(?string $id, ?string $event, string $data)
    {
        $this->id = $id;
        $this->event = $event;
        $this->data = $data;
    }
}
