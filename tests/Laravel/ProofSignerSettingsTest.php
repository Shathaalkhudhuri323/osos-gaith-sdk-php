<?php

namespace Osos\Gaith\Sdk\Tests\Laravel;

use Osos\Gaith\Sdk\Identity\ProductUser;
use Osos\Gaith\Sdk\Identity\ProofSigner;
use Osos\Gaith\Sdk\Laravel\GaithChatbotServiceProvider;
use Osos\Gaith\Sdk\Tests\Identity\KeyPairs;
use Orchestra\Testbench\TestCase;

/**
 * Checks that the proof key can be set in .env in both supported ways: the key text on
 * one line, or the path of the key file.
 */
final class ProofSignerSettingsTest extends TestCase
{
    /** @var array{private: string, public: string} */
    private static $keys;

    /** @var string|null */
    private $keyFile;

    public static function setUpBeforeClass(): void
    {
        self::$keys = KeyPairs::rsa(2048);
    }

    protected function tearDown(): void
    {
        if ($this->keyFile !== null) {
            @unlink($this->keyFile);
        }
        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [GaithChatbotServiceProvider::class];
    }

    private function proof(array $settings): void
    {
        $this->app['config']->set('gaith-chatbot.proof', $settings + ['key_id' => 'qhse-dev-1']);
    }

    private function assertSignsVerifiably(ProofSigner $signer): void
    {
        $parts = explode('.', $signer->sign(ProductUser::create('qhse', '7', '42'), 'chatbot-1'));
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));

        $this->assertSame(1, openssl_verify($parts[0] . '.' . $parts[1], $signature, self::$keys['public'], OPENSSL_ALGO_SHA256));
    }

    public function testAPemWrittenOnOneLineWithEscapedNewlinesWorks(): void
    {
        $this->proof(['private_key' => str_replace("\n", '\n', trim(self::$keys['private']))]);

        $signer = $this->app->make(ProofSigner::class);

        $this->assertSame('qhse-dev-1', $signer->keyId());
        $this->assertSignsVerifiably($signer);
    }

    public function testAPathToAPemFileWorks(): void
    {
        $this->keyFile = tempnam(sys_get_temp_dir(), 'gaith-proof-');
        file_put_contents($this->keyFile, self::$keys['private']);
        $this->proof(['private_key_path' => $this->keyFile]);

        $this->assertSignsVerifiably($this->app->make(ProofSigner::class));
    }

    /**
     * @dataProvider brokenSettings
     */
    public function testBrokenSettingsNameTheSettingNotTheKey(array $settings, string $mentions): void
    {
        $settings = array_map(function ($value) {
            return $value === 'KEY' ? self::$keys['private'] : $value;
        }, $settings);
        $this->app['config']->set('gaith-chatbot.proof', $settings);

        try {
            $this->app->make(ProofSigner::class);
            $this->fail('broken proof settings must not build a signer');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($mentions, $e->getMessage());
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }
    }

    public static function brokenSettings(): array
    {
        return [
            'no key id' => [['private_key' => 'KEY'], 'key_id'],
            'no key' => [['key_id' => 'k1'], 'exactly one'],
            'both key and path' => [['key_id' => 'k1', 'private_key' => 'KEY', 'private_key_path' => '/tmp/k.pem'], 'exactly one'],
            'unreadable path' => [['key_id' => 'k1', 'private_key_path' => '/nowhere/key.pem'], 'cannot be read'],
            'not a key' => [['key_id' => 'k1', 'private_key' => 'not a key'], 'PEM private key'],
        ];
    }
}
