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

    /**
     * @dataProvider invalidSettings
     */
    public function testInvalidSettingsAreRejectedWithAClearMessage(string $baseUrl, string $chatbotId, string $apiKey, ?int $timeout, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new GaithChatbotConfig($baseUrl, $chatbotId, $apiKey, $timeout);
    }

    public static function invalidSettings(): array
    {
        $id = self::VALID_CHATBOT_ID;

        return [
            'empty base URL' => ['', $id, 'key', null, 'baseUrl must not be empty'],
            'relative base URL' => ['not-a-url', $id, 'key', null, 'baseUrl must be an absolute http(s) URL'],
            'not http(s)' => ['ftp://host.example', $id, 'key', null, 'baseUrl must be an absolute http(s) URL'],
            'empty chatbot id' => ['https://host.example', '', 'key', null, 'chatbotId must not be empty'],
            'chatbot id not a UUID' => ['https://host.example', 'not-a-uuid', 'key', null, 'chatbotId must be a valid UUID'],
            'empty API key' => ['https://host.example', $id, '', null, 'apiKey must not be empty'],
            'zero timeout' => ['https://host.example', $id, 'key', 0, 'httpTimeout must be positive'],
        ];
    }
}
