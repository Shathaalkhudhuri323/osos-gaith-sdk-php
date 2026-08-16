<?php

namespace Osos\Gaith\Sdk\Laravel;

use GuzzleHttp\Client as Guzzle;
use Osos\Gaith\Sdk\Config\GaithChatbotConfig;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Http\GaithHttpTransport;
use Osos\Gaith\Sdk\Streaming\Adapters\GuzzleStreamingClient;

final class GaithChatbotClientFactory
{
    /** @var array<string, mixed> */
    private $connections;

    /** @var array<string, GaithChatbotClient> */
    private $resolved = [];

    /**
     * @param array<string, mixed> $connections
     */
    public function __construct(array $connections)
    {
        $this->connections = $connections;
    }

    public function get(?string $name = null): GaithChatbotClient
    {
        $name = $name ?? 'default';

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (!isset($this->connections[$name])) {
            throw new \InvalidArgumentException("Unknown GAITH chatbot connection: {$name}");
        }

        $conn = $this->connections[$name];

        $config = new GaithChatbotConfig(
            (string) $conn['base_url'],
            (string) $conn['chatbot_id'],
            (string) $conn['api_key'],
            isset($conn['http_timeout']) ? (int) $conn['http_timeout'] : null
        );

        $jsonGuzzle = new Guzzle([
            'timeout' => $config->httpTimeout() ?? 30,
        ]);
        $streamingGuzzle = new Guzzle([
            'timeout' => 0,
        ]);

        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $transport = new GaithHttpTransport($config, $jsonGuzzle, $factory, $factory);
        $streamingClient = new GuzzleStreamingClient($streamingGuzzle);

        return $this->resolved[$name] = new GaithChatbotClient($transport, $streamingClient);
    }
}
