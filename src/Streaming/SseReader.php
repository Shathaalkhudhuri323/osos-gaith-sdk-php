<?php

namespace Osos\Gaith\Sdk\Streaming;

final class SseReader
{
    /**
     * @return \Generator<SseFrame>
     */
    public function read(StreamHandle $handle): \Generator
    {
        $buffer = '';

        while (($chunk = $handle->read()) !== null) {
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawFrame = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $frame = $this->parseFrame($rawFrame);
                if ($frame !== null) {
                    yield $frame;
                }
            }
        }
    }

    private function parseFrame(string $rawFrame): ?SseFrame
    {
        $id = null;
        $event = null;
        $dataLines = [];
        $sawComment = false;

        foreach (explode("\n", $rawFrame) as $line) {
            if ($line === '') {
                continue;
            }
            if (strpos($line, ':') === 0) {
                $sawComment = true; // e.g. the server's keep-alive
                continue;
            }

            if (strpos($line, 'id:') === 0) {
                $id = trim(substr($line, 3));
            } elseif (strpos($line, 'event:') === 0) {
                $event = trim(substr($line, 6));
            } elseif (strpos($line, 'data:') === 0) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        if ($dataLines === [] && $event === null) {
            // A comment-only frame is a keep-alive and is reported as such; a frame
            // with neither comment nor payload carries nothing and is dropped.
            return $sawComment ? new SseFrame(null, null, '', true) : null;
        }

        return new SseFrame($id, $event, implode("\n", $dataLines));
    }
}
