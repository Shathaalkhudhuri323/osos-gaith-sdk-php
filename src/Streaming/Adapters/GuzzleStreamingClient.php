<?php

namespace Osos\Gaith\Sdk\Streaming\Adapters;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Osos\Gaith\Sdk\Exceptions\GaithApiException;
use Osos\Gaith\Sdk\Exceptions\GaithAuthException;
use Osos\Gaith\Sdk\Exceptions\GaithForbiddenException;
use Osos\Gaith\Sdk\Exceptions\GaithGoneException;
use Osos\Gaith\Sdk\Exceptions\GaithNotFoundException;
use Osos\Gaith\Sdk\Exceptions\GaithRateLimitException;
use Osos\Gaith\Sdk\Exceptions\GaithValidationException;
use Osos\Gaith\Sdk\Streaming\StreamDroppedException;
use Osos\Gaith\Sdk\Streaming\StreamHandle;
use Osos\Gaith\Sdk\Streaming\StreamingHttpClientInterface;
use Psr\Http\Message\RequestInterface;

final class GuzzleStreamingClient implements StreamingHttpClientInterface
{
    /** @var ClientInterface */
    private $guzzle;

    public function __construct(ClientInterface $guzzle)
    {
        $this->guzzle = $guzzle;
    }

    public function sendStreaming(RequestInterface $request): StreamHandle
    {
        try {
            $response = $this->guzzle->send($request, ['stream' => true]);
        } catch (ConnectException $e) {
            throw new StreamDroppedException($e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw $this->mapErrorResponse($e->getResponse());
            }

            throw new StreamDroppedException($e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw $this->mapErrorResponse($response);
        }

        return new GuzzleStreamHandle($response->getBody());
    }

    private function mapErrorResponse(\Psr\Http\Message\ResponseInterface $response): GaithApiException
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $code = is_array($decoded) ? ($decoded['error']['code'] ?? null) : null;
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? $response->getReasonPhrase()) : $response->getReasonPhrase();

        $class = $this->exceptionClassForStatus($status);

        return new $class($status, $code, (string) $message, $body);
    }

    private function exceptionClassForStatus(int $status): string
    {
        switch ($status) {
            case 401:
                return GaithAuthException::class;
            case 403:
                return GaithForbiddenException::class;
            case 404:
                return GaithNotFoundException::class;
            case 410:
                return GaithGoneException::class;
            case 422:
                return GaithValidationException::class;
            case 429:
                return GaithRateLimitException::class;
            default:
                return GaithApiException::class;
        }
    }
}
