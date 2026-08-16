<?php

namespace Osos\Gaith\Sdk\Tests\Laravel;

use Illuminate\Container\Container;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Laravel\GaithChatbotClientFactory;
use Osos\Gaith\Sdk\Laravel\GaithChatbotServiceProvider;
use PHPUnit\Framework\TestCase;

final class GaithChatbotServiceProviderTest extends TestCase
{
    private function appWithConfig(array $connections, string $default = 'default'): Container
    {
        $app = new Container();
        $app->singleton('config', function () use ($connections, $default) {
            return new \Illuminate\Config\Repository([
                'gaith-chatbot' => [
                    'default' => $default,
                    'connections' => $connections,
                ],
            ]);
        });

        return $app;
    }

    private function validConnection(): array
    {
        return [
            'base_url' => 'https://gaith-backend-dev.osos.om',
            'chatbot_id' => '11111111-1111-1111-1111-111111111111',
            'api_key' => 'test-key',
            'http_timeout' => 30,
        ];
    }

    public function testDefaultClientIsBoundAndResolvable(): void
    {
        $app = $this->appWithConfig(['default' => $this->validConnection()]);
        $provider = new GaithChatbotServiceProvider($app);
        $provider->register();

        $client = $app->make(GaithChatbotClient::class);

        $this->assertInstanceOf(GaithChatbotClient::class, $client);
    }

    public function testFactoryResolvesNamedConnection(): void
    {
        $app = $this->appWithConfig([
            'default' => $this->validConnection(),
            'hr' => $this->validConnection(),
        ]);
        $provider = new GaithChatbotServiceProvider($app);
        $provider->register();

        $factory = $app->make(GaithChatbotClientFactory::class);

        $this->assertInstanceOf(GaithChatbotClient::class, $factory->get('hr'));
    }

    public function testFactoryThrowsForUnknownConnection(): void
    {
        $app = $this->appWithConfig(['default' => $this->validConnection()]);
        $provider = new GaithChatbotServiceProvider($app);
        $provider->register();

        $factory = $app->make(GaithChatbotClientFactory::class);

        $this->expectException(\InvalidArgumentException::class);

        $factory->get('does-not-exist');
    }
}
