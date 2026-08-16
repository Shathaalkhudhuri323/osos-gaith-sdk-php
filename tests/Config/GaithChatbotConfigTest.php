<?php

namespace Osos\Gaith\Sdk\Tests\Config;

use Osos\Gaith\Sdk\Config\GaithChatbotConfig;
use PHPUnit\Framework\TestCase;

final class GaithChatbotConfigTest extends TestCase
{
    private const VALID_CHATBOT_ID = '11111111-1111-1111-1111-111111111111';

    public function testValidConfigExposesFields(): void
    {
        $config = new GaithChatbotConfig(
            'https://gaith-backend-dev.osos.om',
            self::VALID_CHATBOT_ID,
            'sk-test-key',
            45
        );

        $this->assertSame('https://gaith-backend-dev.osos.om', $config->baseUrl());
        $this->assertSame(self::VALID_CHATBOT_ID, $config->chatbotId());
        $this->assertSame('sk-test-key', $config->apiKey());
        $this->assertSame(45, $config->httpTimeout());
    }

    public function testTimeoutDefaultsToNull(): void
    {
        $config = new GaithChatbotConfig('https://host.example', self::VALID_CHATBOT_ID, 'key');

        $this->assertNull($config->httpTimeout());
    }

    public function testEmptyBaseUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUrl must not be empty');

        new GaithChatbotConfig('', self::VALID_CHATBOT_ID, 'key');
    }

    public function testNonAbsoluteBaseUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUrl must be an absolute http(s) URL');

        new GaithChatbotConfig('not-a-url', self::VALID_CHATBOT_ID, 'key');
    }

    public function testNonHttpSchemeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUrl must be an absolute http(s) URL');

        new GaithChatbotConfig('ftp://host.example', self::VALID_CHATBOT_ID, 'key');
    }

    public function testEmptyChatbotIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chatbotId must not be empty');

        new GaithChatbotConfig('https://host.example', '', 'key');
    }

    public function testNonUuidChatbotIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chatbotId must be a valid UUID');

        new GaithChatbotConfig('https://host.example', 'not-a-uuid', 'key');
    }

    public function testEmptyApiKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey must not be empty');

        new GaithChatbotConfig('https://host.example', self::VALID_CHATBOT_ID, '');
    }

    public function testNonPositiveTimeoutThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('httpTimeout must be positive');

        new GaithChatbotConfig('https://host.example', self::VALID_CHATBOT_ID, 'key', 0);
    }
}
