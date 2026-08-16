<?php

namespace Osos\Gaith\Sdk\Streaming;

use Osos\Gaith\Sdk\Models\FileRef;

final class FileEvent extends ChatEvent
{
    /** @var FileRef */
    private $file;

    public function __construct(FileRef $file)
    {
        $this->file = $file;
    }

    public function file(): FileRef { return $this->file; }
}
