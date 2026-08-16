<?php

namespace Osos\Gaith\Sdk\Streaming;

interface StreamHandle
{
    /**
     * Read the next available chunk of bytes, or null at clean end-of-stream.
     * Implementations block until data is available or the stream ends.
     *
     * @throws StreamDroppedException on a mid-read connection failure
     */
    public function read(): ?string;

    public function close(): void;
}
