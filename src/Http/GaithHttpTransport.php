<?php

namespace Osos\Gaith\Sdk\Http;

use Osos\Gaith\Sdk\Config\GaithChatbotConfig;
use Osos\Gaith\Sdk\Exceptions\GaithApiException;
use Osos\Gaith\Sdk\Exceptions\GaithAuthException;
use Osos\Gaith\Sdk\Exceptions\GaithForbiddenException;
use Osos\Gaith\Sdk\Exceptions\GaithGoneException;
use Osos\Gaith\Sdk\Exceptions\GaithNotFoundException;
use Osos\Gaith\Sdk\Exceptions\GaithRateLimitException;
use Osos\Gaith\Sdk\Exceptions\GaithValidationException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class GaithHttpTransport
{
    /** @var GaithChatbotConfig */
    private $config;

    /** @var ClientInterface */
    private $httpClient;

    /** @var RequestFactoryInterface */
    private $requestFactory;

    /** @var StreamFactoryInterface */
    private $streamFactory;

    public function __construct(
        GaithChatbotConfig $config,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory
    ) {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    public function baseUri(): string
    {
        return $this->config->baseUrl() . '/api/v1/chatbots/' . $this->config->chatbotId() . '/';
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, string $queryString = ''): array
    {
        $request = $this->buildRequest('GET', $path, $queryString);
        $response = $this->send($request);

        return $this->decodeJson($response);
    }

    public function delete(string $path, string $queryString = ''): void
    {
        $request = $this->buildRequest('DELETE', $path, $queryString);
        $this->send($request);
    }

    private function buildRequest(string $method, string $path, string $queryString): RequestInterface
    {
        $uri = $this->baseUri() . ltrim($path, '/');
        if ($queryString !== '') {
            $uri .= '?' . $queryString;
        }

        return $this->requestFactory
            ->createRequest($method, $uri)
            ->withHeader('X-API-Key', $this->config->apiKey())
            ->withHeader('Accept', 'application/json');
    }

    private function send(RequestInterface $request): ResponseInterface
    {
        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            return $response;
        }

        throw $this->mapError($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function mapError(ResponseInterface $response): GaithApiException
    {
        $status = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        [$serverCode, $message] = $this->parseErrorBody($rawBody, $response->getReasonPhrase());

        $class = $this->exceptionClassForStatus($status);

        return new $class($status, $serverCode, $rawBody, $message);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function parseErrorBody(string $rawBody, string $reasonPhrase): array
    {
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            return [null, $rawBody !== '' ? $rawBody : $reasonPhrase];
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $code = $decoded['error']['code'] ?? null;
            $message = $decoded['error']['message'] ?? $reasonPhrase;

            return [$code !== null ? (string) $code : null, (string) $message];
        }

        if (array_key_exists('detail', $decoded)) {
            return $this->parseDetail($decoded['detail'], $reasonPhrase);
        }

        if (isset($decoded['code']) || isset($decoded['message'])) {
            $code = $decoded['code'] ?? null;
            $message = $decoded['message'] ?? $reasonPhrase;

            return [$code !== null ? (string) $code : null, (string) $message];
        }

        return [null, $rawBody];
    }

    /**
     * @param mixed $detail
     * @return array{0: string|null, 1: string}
     */
    private function parseDetail($detail, string $reasonPhrase): array
    {
        if (is_string($detail)) {
            return [null, $detail];
        }

        if (is_array($detail) && (isset($detail['code']) || isset($detail['message']) || isset($detail['msg']))) {
            $code = $detail['code'] ?? null;
            $message = $detail['message'] ?? $detail['msg'] ?? $reasonPhrase;

            return [$code !== null ? (string) $code : null, (string) $message];
        }

        if (is_array($detail) && isset($detail[0]) && is_array($detail[0])) {
            $messages = [];
            foreach ($detail as $item) {
                if (is_array($item) && isset($item['msg'])) {
                    $messages[] = (string) $item['msg'];
                }
            }

            return [null, $messages !== [] ? implode('; ', $messages) : $reasonPhrase];
        }

        return [null, $reasonPhrase];
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
