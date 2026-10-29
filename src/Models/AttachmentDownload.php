<?php

namespace Osos\Gaith\Sdk\Models;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A downloaded attachment with what a proxy needs to serve it on: its bytes,
 * media type and file name, as GAITH reported them.
 */
final class AttachmentDownload
{
    /** @var StreamInterface */
    private $stream;
    /** @var string|null */
    private $mediaType;
    /** @var string|null */
    private $filename;
    /** @var int|null */
    private $sizeBytes;

    public function __construct(StreamInterface $stream, ?string $mediaType, ?string $filename, ?int $sizeBytes)
    {
        $this->stream = $stream;
        $this->mediaType = $mediaType;
        $this->filename = $filename;
        $this->sizeBytes = $sizeBytes;
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $type = trim(explode(';', $response->getHeaderLine('Content-Type'))[0]);
        $length = $response->getHeaderLine('Content-Length');

        return new self(
            $response->getBody(),
            $type !== '' ? strtolower($type) : null,
            self::filenameFrom($response->getHeaderLine('Content-Disposition')),
            ctype_digit($length) ? (int) $length : $response->getBody()->getSize()
        );
    }

    /**
     * The RFC 5987 `filename*` (UTF-8, e.g. Arabic names) wins over the plain
     * `filename` fallback, as browsers do.
     */
    private static function filenameFrom(string $disposition): ?string
    {
        if ($disposition === '') {
            return null;
        }
        if (preg_match("/filename\\*\\s*=\\s*UTF-8''([^;]+)/i", $disposition, $m)) {
            return rawurldecode(trim($m[1]));
        }
        if (preg_match('/filename\s*=\s*"([^"]*)"/i', $disposition, $m) || preg_match('/filename\s*=\s*([^;]+)/i', $disposition, $m)) {
            $name = trim($m[1]);
            return $name !== '' ? $name : null;
        }

        return null;
    }

    public function stream(): StreamInterface { return $this->stream; }
    public function mediaType(): ?string { return $this->mediaType; }
    public function filename(): ?string { return $this->filename; }
    public function sizeBytes(): ?int { return $this->sizeBytes; }
}
