<?php

namespace Osos\Gaith\Sdk\Tests\Identity;

use Osos\Gaith\Sdk\Identity\ProductUser;
use Osos\Gaith\Sdk\Identity\ProofSigner;
use PHPUnit\Framework\TestCase;

/**
 * The SDK only creates proofs; GAITH checks them. These tests check each proof the way
 * GAITH will, using only the public key, so a proof that passes here will pass in GAITH.
 */
final class ProofSignerTest extends TestCase
{
    private const CHATBOT = '11111111-1111-1111-1111-111111111111';
    private const NOW = 1793530000;

    /** @var array{private: string, public: string} */
    private static $keys;

    public static function setUpBeforeClass(): void
    {
        self::$keys = KeyPairs::rsa(2048);
    }

    private function signer(string $keyId = 'qhse-dev-1'): ProofSigner
    {
        return new ProofSigner(self::$keys['private'], $keyId);
    }

    private function user(): ProductUser
    {
        return ProductUser::create('qhse', '7', '42');
    }

    /** @return array{0: array, 1: array, 2: string, 3: string} header, claims, signed part, signature */
    private static function split(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        return [
            json_decode(self::b64($parts[0]), true),
            json_decode(self::b64($parts[1]), true),
            $parts[0] . '.' . $parts[1],
            self::b64($parts[2]),
        ];
    }

    private static function b64(string $part): string
    {
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $part, 'JWT parts are base64url without padding');

        return base64_decode(strtr($part, '-_', '+/'), true);
    }

    private static function verifies(string $signed, string $signature, string $publicKey): bool
    {
        return openssl_verify($signed, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public function testTheProofCarriesWhoIsChattingForWhichChatbot(): void
    {
        [$header, $claims] = self::split($this->signer()->sign($this->user(), self::CHATBOT, self::NOW));

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'qhse-dev-1'], $header);
        $this->assertSame('qhse', $claims['iss']);
        $this->assertSame('42', $claims['sub']);
        $this->assertSame('7', $claims['tenant']);
        $this->assertSame(self::CHATBOT, $claims['aud']);
        $this->assertSame(self::NOW, $claims['iat']);
        $this->assertSame(self::NOW + 120, $claims['exp']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $claims['jti']);
        $this->assertSame(['iss', 'sub', 'tenant', 'aud', 'iat', 'exp', 'jti'], array_keys($claims));
    }

    public function testThePublicKeyVerifiesTheProof(): void
    {
        [, , $signed, $signature] = self::split($this->signer()->sign($this->user(), self::CHATBOT, self::NOW));

        $this->assertTrue(self::verifies($signed, $signature, self::$keys['public']));
    }

    public function testAnAlteredProofFailsVerification(): void
    {
        $jwt = $this->signer()->sign($this->user(), self::CHATBOT, self::NOW);
        [$header, $claims, , $signature] = self::split($jwt);

        $claims['sub'] = '43'; // someone else at the same customer
        $forged = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=')
            . '.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

        $this->assertFalse(self::verifies($forged, $signature, self::$keys['public']));
    }

    public function testEveryProofHasItsOwnId(): void
    {
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = self::split($this->signer()->sign($this->user(), self::CHATBOT, self::NOW))[1]['jti'];
        }

        $this->assertCount(20, array_unique($ids));
    }

    public function testTheKeyNeverShowsInADump(): void
    {
        $dump = print_r($this->signer(), true) . var_export((array) $this->signer(), true);

        $this->assertStringNotContainsString('PRIVATE KEY', $dump);
        $this->assertStringContainsString('qhse-dev-1', print_r($this->signer(), true));
    }

    public function testAKeyThatIsNotAPrivateKeyIsRefusedWithoutEchoingIt(): void
    {
        try {
            new ProofSigner(self::$keys['public'], 'qhse-dev-1');
            $this->fail('a public key must not be accepted as the signing key');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('BEGIN', $e->getMessage());
        }
    }

    /**
     * @dataProvider weakKeys
     */
    public function testWeakOrNonRsaKeysAreRefused(string $pem): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProofSigner($pem, 'qhse-dev-1');
    }

    public static function weakKeys(): array
    {
        return [
            'RSA under 2048 bits' => [KeyPairs::rsa(1024)['private']],
            'not RSA' => [KeyPairs::ec()['private']],
            'not a key' => ['hello'],
        ];
    }
}
