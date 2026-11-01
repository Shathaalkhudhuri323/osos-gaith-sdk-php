<?php

/**
 * Checks an identity proof the way GAITH must (build plan step 3.2), for the fake GAITH
 * server next to this file. Not part of the SDK: the SDK only signs.
 *
 * Every check here is one GAITH needs too, so this doubles as a reference for that work:
 * a fixed algorithm, the signature, issuer, audience, time window with clock skew, a
 * short lifetime, plain ids, and a `jti` that is never accepted twice.
 */
final class FakeGaithProofVerifier
{
    private const SKEW_SECONDS = 60;
    private const MAX_LIFETIME_SECONDS = 300;

    /** @var string */
    private $publicKey;

    /** @var string */
    private $issuer;

    /** @var string */
    private $chatbotId;

    /** @var string file remembering seen jti values until they expire */
    private $replayFile;

    public function __construct(string $publicKeyPem, string $issuer, string $chatbotId, string $replayFile)
    {
        $this->publicKey = $publicKeyPem;
        $this->issuer = $issuer;
        $this->chatbotId = $chatbotId;
        $this->replayFile = $replayFile;
    }

    /**
     * @return array<string, mixed> the proof's claims
     * @throws \RuntimeException naming the first check that failed
     */
    public function verify(?string $jwt, int $now): array
    {
        $parts = explode('.', (string) $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('missing or malformed proof');
        }
        [$h, $c, $s] = $parts;
        $header = json_decode((string) self::b64($h), true);
        $claims = json_decode((string) self::b64($c), true);
        $signature = self::b64($s);
        if (!is_array($header) || !is_array($claims) || $signature === null) {
            throw new \RuntimeException('malformed proof');
        }

        // Never let the token pick its own algorithm ("none", or HS256 keyed with the public key).
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new \RuntimeException('algorithm not allowed');
        }
        if (openssl_verify($h . '.' . $c, $signature, $this->publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('bad signature');
        }

        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new \RuntimeException('wrong issuer');
        }
        if (($claims['aud'] ?? null) !== $this->chatbotId) {
            throw new \RuntimeException('proof is for another chatbot');
        }

        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp)) {
            throw new \RuntimeException('missing times');
        }
        if ($now > $exp + self::SKEW_SECONDS) {
            throw new \RuntimeException('expired');
        }
        if ($iat > $now + self::SKEW_SECONDS) {
            throw new \RuntimeException('issued in the future');
        }
        if ($exp - $iat > self::MAX_LIFETIME_SECONDS) {
            throw new \RuntimeException('lifetime too long');
        }

        foreach (['sub', 'tenant'] as $id) {
            if (!is_string($claims[$id] ?? null) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $claims[$id])) {
                throw new \RuntimeException("bad {$id}");
            }
        }

        $jti = $claims['jti'] ?? null;
        if (!is_string($jti) || !preg_match('/^[0-9a-f]{32}$/', $jti)) {
            throw new \RuntimeException('bad jti');
        }
        // Checked last, so a proof that fails another check does not burn its jti.
        $this->rememberOnce($jti, $exp + self::SKEW_SECONDS, $now);

        return $claims;
    }

    private function rememberOnce(string $jti, int $forgetAt, int $now): void
    {
        $handle = fopen($this->replayFile, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            // GAITH refuses when its replay store (Redis) is down; so does the fake.
            throw new \RuntimeException('replay store unavailable');
        }
        try {
            $seen = json_decode((string) stream_get_contents($handle), true) ?: [];
            $seen = array_filter($seen, static function (int $until) use ($now): bool {
                return $until >= $now;
            });
            if (isset($seen[$jti])) {
                throw new \RuntimeException('proof already used');
            }
            $seen[$jti] = $forgetAt;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($seen));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function b64(string $part): ?string
    {
        $raw = base64_decode(strtr($part, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
