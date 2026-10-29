<?php

namespace Osos\Gaith\Sdk\Tests\Laravel;

use Osos\Gaith\Sdk\Laravel\GaithChatbotServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * The widget routes expose endpoints, so installing the package alone must not
 * register them - the host opts in once it has chosen the guarding middleware.
 */
final class GaithChatRoutesDisabledTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [GaithChatbotServiceProvider::class];
    }

    public function testWidgetRoutesAreNotRegisteredByDefault(): void
    {
        $this->assertNull($this->app['router']->getRoutes()->getByName('gaith-chat.stream'));
        $this->assertNull($this->app['router']->getRoutes()->getByName('gaith-chat.messages'));
        $this->postJson('/api/gaith/stream', ['message' => 'hello'])->assertStatus(404);
    }
}
