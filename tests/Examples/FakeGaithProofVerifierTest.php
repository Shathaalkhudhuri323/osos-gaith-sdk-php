<?php

namespace Osos\Gaith\Sdk\Tests\Examples;

use Osos\Gaith\Sdk\Identity\ProductUser;
use Osos\Gaith\Sdk\Identity\ProofSigner;
use Osos\Gaith\Sdk\Tests\Identity\KeyPairs;
use PHPUnit\Framework\TestCase;

/**
 * The fake GAITH accepts exactly what the SDK signs and refuses each kind of bad proof
 * GAITH must refuse (build plan step 3.2), so the two stay in step.
 */
final class FakeGaithProofVerifierTest extends TestCase
{
    private const CHATBOT = '11111111-1111-1111-1111-111111111111';
    private const NOW = 1793530000;

    /** @var array{private: string, public: string} */
    private static $keys;

    /** @var string */
    private $replayFile;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/fake-gaith/FakeGaithProofVerifier.php';
        self::$keys = KeyPairs::rsa(2048);
    }

    protected function setUp(): void
    {
        $this->replayFile = tempnam(sys_get_temp_dir(), 'fake-gaith-jti-');
    }

    protected function tearDown(): void
    {
        @unlink($this->replayFile);
    }

    private function verifier(string $issuer = 'qhse', string $chatbot = self::CHATBOT, ?string $publicKey = null): \FakeGaithProofVerifier
    {
        return new \FakeGaithProofVerifier($publicKey ?? self::$keys['public'], $issuer, $chatbot, $this->replayFile);
    }

    private function proof(int $at = self::NOW, string $audience = self::CHATBOT, ?string $privateKey = null): string
    {
        return (new ProofSigner($privateKey ?? self::$keys['private'], 'qhse-dev-1'))
            ->sign(ProductUser::create('qhse', '7', '42'), $audience, $at);
    }

    private function assertRefused(string $reason, callable $check): void
    {
        try {
            $check();
            $this->fail("expected refusal: {$reason}");
        } catch (\RuntimeException $e) {
            $this->assertSame($reason, $e->getMessage());
        }
    }

    private static function b64(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function testAProofTheSdkSignedIsAccepted(): void
    {
        $claims = $this->verifier()->verify($this->proof(), self::NOW);

        $this->assertSame(['qhse', '7', '42'], [$claims['iss'], $claims['tenant'], $claims['sub']]);
    }

    public function testItIsAcceptedOnlyOnce(): void
    {
        $proof = $this->proof();
        $this->verifier()->verify($proof, self::NOW);

        $this->assertRefused('proof already used', function () use ($proof) {
            $this->verifier()->verify($proof, self::NOW + 1);
        });
    }

    public function testClockSkewIsToleratedUpToAMinute(): void
    {
        $this->verifier()->verify($this->proof(self::NOW), self::NOW + 120 + 59);
        $this->verifier()->verify($this->proof(self::NOW + 59), self::NOW);

        $this->assertRefused('expired', function () {
            $this->verifier()->verify($this->proof(self::NOW), self::NOW + 120 + 61);
        });
        $this->assertRefused('issued in the future', function () {
            $this->verifier()->verify($this->proof(self::NOW + 61), self::NOW);
        });
    }

    public function testAProofForAnotherChatbotOrProductIsRefused(): void
    {
        $this->assertRefused('proof is for another chatbot', function () {
            $this->verifier()->verify($this->proof(self::NOW, '22222222-2222-2222-2222-222222222222'), self::NOW);
        });
        $this->assertRefused('wrong issuer', function () {
            $this->verifier('erp')->verify($this->proof(), self::NOW);
        });
    }

    public function testAForgedOrForeignProofIsRefused(): void
    {
        $parts = explode('.', $this->proof());
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $claims['sub'] = '43';
        $this->assertRefused('bad signature', function () use ($parts, $claims) {
            $this->verifier()->verify($parts[0] . '.' . self::b64(json_encode($claims)) . '.' . $parts[2], self::NOW);
        });

        $other = KeyPairs::rsa(2048);
        $this->assertRefused('bad signature', function () use ($other) {
            $this->verifier()->verify($this->proof(self::NOW, self::CHATBOT, $other['private']), self::NOW);
        });
    }

    public function testATokenCannotChooseItsOwnAlgorithm(): void
    {
        $claims = self::b64(json_encode(['iss' => 'qhse', 'sub' => '42', 'tenant' => '7', 'aud' => self::CHATBOT,
            'iat' => self::NOW, 'exp' => self::NOW + 120, 'jti' => str_repeat('a', 32)]));

        foreach (['none', 'HS256'] as $alg) {
            $header = self::b64(json_encode(['alg' => $alg, 'typ' => 'JWT']));
            $forged = $header . '.' . $claims . '.' . self::b64(hash_hmac('sha256', $header . '.' . $claims, self::$keys['public'], true));
            $this->assertRefused('algorithm not allowed', function () use ($forged) {
                $this->verifier()->verify($forged, self::NOW);
            });
        }
    }

    public function testNoProofIsRefused(): void
    {
        $this->assertRefused('missing or malformed proof', function () {
            $this->verifier()->verify(null, self::NOW);
        });
    }
}
