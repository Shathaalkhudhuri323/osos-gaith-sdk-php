<?php
// src/Streaming/Adapters/GuzzleStreamHandle.php
namespace Osos\Gaith\Sdk\Streaming\Adapters;

use Osos\Gaith\Sdk\Streaming\StreamDroppedException;
use Osos\Gaith\Sdk\Streaming\StreamHandle;
use Psr\Http\Message\StreamInterface;

final class GuzzleStreamHandle implements StreamHandle
{
    /** @var StreamInterface */
    private $body;

    public function __construct(StreamInterface $body)
    {
        $this->body = $body;
    }

    public function read(): ?string
    {
        if ($this->body->eof()) {
            return null;
        }

        try {
            $chunk = $this->body->read(8192);
        } catch (\RuntimeException $e) {
            throw new StreamDroppedException($e->getMessage(), 0, $e);
        }

        return $chunk === '' ? null : $chunk;
    }

    public function close(): void
    {
        $this->body->close();
    }
}
