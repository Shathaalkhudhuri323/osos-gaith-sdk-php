<?php

namespace Osos\Gaith\Sdk\Laravel;

use Illuminate\Support\ServiceProvider;
use Osos\Gaith\Sdk\GaithChatbotClient;
use Osos\Gaith\Sdk\Laravel\Http\AuthGaithUserResolver;
use Osos\Gaith\Sdk\Laravel\Http\GaithUserResolver;

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

        $this->app->bind(GaithUserResolver::class, function ($app) {
            return $app->make($app['config']->get('gaith-chatbot.widget.user_resolver', AuthGaithUserResolver::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/gaith-chatbot.php' => config_path('gaith-chatbot.php'),
        ], 'gaith-chatbot-config');

        // The built widget, served by the host so it always matches these routes' version.
        $this->publishes([
            __DIR__ . '/../../dist' => public_path('vendor/gaith-chat'),
        ], 'gaith-chat-assets');

        if ($this->app['config']->get('gaith-chatbot.widget.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        }
    }
}
