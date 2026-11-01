<?php

namespace Osos\Gaith\Sdk\Tests\Laravel;

use Osos\Gaith\Sdk\Laravel\GaithChatbotServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * The chat box script is served by the SDK itself, so `composer update` ships a new chat
 * box with no publish step, and it loads from a plain <script> tag, which cannot carry
 * the host's sign-in header.
 */
final class GaithChatScriptRouteTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [GaithChatbotServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('gaith-chatbot.widget.enabled', true);
        $app['config']->set('gaith-chatbot.widget.prefix', 'api/gaith');
        $app['config']->set('gaith-chatbot.widget.middleware', ['auth']);
    }

    private static function builtScript(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/dist/gaith-chat.js');
    }

    public function testTheScriptLoadsWithoutSignInWhileTheChatRoutesStillNeedIt(): void
    {
        $response = $this->get('/api/gaith/gaith-chat.js');

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/javascript', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(self::builtScript(), $response->getContent());

        $this->getJson('/api/gaith/config')->assertStatus(401);
    }

    public function testBrowsersReuseItBrieflyAndThenCheckForAChange(): void
    {
        $response = $this->get('/api/gaith/gaith-chat.js');

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=300', $cache);
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $again = $this->get('/api/gaith/gaith-chat.js', ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());

        $this->get('/api/gaith/gaith-chat.js', ['If-None-Match' => '"something-else"'])->assertStatus(200);
    }

    public function testTheSourceMapIsNotServed(): void
    {
        $this->get('/api/gaith/gaith-chat.js.map')->assertStatus(404);
    }
}
