<?php

namespace Osos\Gaith\Sdk\Laravel;

use Illuminate\Support\ServiceProvider;
use Osos\Gaith\Sdk\GaithChatbotClient;

final class GaithChatbotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/gaith-chatbot.php', 'gaith-chatbot');

        $this->app->singleton(GaithChatbotClientFactory::class, function ($app) {
            return new GaithChatbotClientFactory((array) $app['config']->get('gaith-chatbot.connections', []));
        });

        $this->app->bind(GaithChatbotClient::class, function ($app) {
            $default = $app['config']->get('gaith-chatbot.default', 'default');

            return $app->make(GaithChatbotClientFactory::class)->get($default);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/gaith-chatbot.php' => config_path('gaith-chatbot.php'),
        ], 'gaith-chatbot-config');
    }
}
