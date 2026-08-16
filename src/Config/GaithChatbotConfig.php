<?php

namespace Osos\Gaith\Sdk\Config;

final class GaithChatbotConfig
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $chatbotId;

    /** @var string */
    private $apiKey;

    /** @var int|null */
    private $httpTimeout;

    public function __construct(string $baseUrl, string $chatbotId, string $apiKey, ?int $httpTimeout = null)
    {
        $this->assertValidBaseUrl($baseUrl);
        $this->assertValidChatbotId($chatbotId);
        $this->assertValidApiKey($apiKey);
        $this->assertValidTimeout($httpTimeout);

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->chatbotId = $chatbotId;
        $this->apiKey = $apiKey;
        $this->httpTimeout = $httpTimeout;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function chatbotId(): string
    {
        return $this->chatbotId;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function httpTimeout(): ?int
    {
        return $this->httpTimeout;
    }

    private function assertValidBaseUrl(string $baseUrl): void
    {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('baseUrl must not be empty');
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || $host === null || $host === '') {
            throw new \InvalidArgumentException('baseUrl must be an absolute http(s) URL');
        }
    }

    private function assertValidChatbotId(string $chatbotId): void
    {
        if ($chatbotId === '') {
            throw new \InvalidArgumentException('chatbotId must not be empty');
        }

        if (!\Ramsey\Uuid\Uuid::isValid($chatbotId)) {
            throw new \InvalidArgumentException('chatbotId must be a valid UUID');
        }
    }

    private function assertValidApiKey(string $apiKey): void
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey must not be empty');
        }
    }

    private function assertValidTimeout(?int $httpTimeout): void
    {
        if ($httpTimeout !== null && $httpTimeout <= 0) {
            throw new \InvalidArgumentException('httpTimeout must be positive');
        }
    }
}
