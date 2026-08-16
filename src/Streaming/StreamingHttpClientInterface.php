<?php

namespace Osos\Gaith\Sdk\Streaming;

use Osos\Gaith\Sdk\Exceptions\GaithApiException;
use Psr\Http\Message\RequestInterface;

interface StreamingHttpClientInterface
{
    /**
     * @throws GaithApiException on a non-2xx response received before any bytes are streamed
     */
    public function sendStreaming(RequestInterface $request): StreamHandle;
}
