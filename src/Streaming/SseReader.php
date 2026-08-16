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

        foreach (explode("\n", $rawFrame) as $line) {
            if ($line === '' || strpos($line, ':') === 0) {
                continue; // blank or comment line
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
            return null; // e.g. a frame that was only heartbeat/comment lines
        }

        return new SseFrame($id, $event, implode("\n", $dataLines));
    }
}
