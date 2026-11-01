<?php

namespace Osos\Gaith\Sdk\Identity;

/**
 * Signs the identity proof the host sends GAITH with every chat request: a short-lived
 * RS256 JWT saying which product user is chatting, for which chatbot.
 *
 * GAITH checks it with the public key registered on the chatbot's API key, so a proof
 * is only as trustworthy as the host's session behind it. The SDK signs one per request
 * and never reuses it; GAITH refuses a repeated `jti`.
 *
 * Signing is done with ext-openssl directly. Verification - where JWT libraries earn
 * their keep - happens in GAITH, not here.
 */
final class ProofSigner
{
    /** GAITH allows 60 s of clock skew on top. */
    public const LIFETIME_SECONDS = 120;

    private const MIN_RSA_BITS = 2048;

    /** @var \OpenSSLAsymmetricKey|resource kept as a key handle, so the PEM text is not held in a property */
    private $key;

    /** @var string */
    private $keyId;

    /**
     * @param string $privateKeyPem PEM-encoded RSA private key, at least 2048 bits, unencrypted
     * @param string $keyId the id GAITH has this key's public half registered under
     * @throws \InvalidArgumentException for an unusable key; the message never contains the key
     */
    public function __construct(string $privateKeyPem, string $keyId)
    {
        $keyId = trim($keyId);
        if ($keyId === '') {
            throw new \InvalidArgumentException('proof key id must not be empty');
        }

        $key = @openssl_pkey_get_private($privateKeyPem);
        while (openssl_error_string() !== false) {
            // drain OpenSSL's error queue so a later, unrelated call does not report these
        }
        if ($key === false) {
            throw new \InvalidArgumentException('proof private key is not a readable, unencrypted PEM private key');
        }

        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < self::MIN_RSA_BITS) {
            throw new \InvalidArgumentException('proof private key must be an RSA key of at least ' . self::MIN_RSA_BITS . ' bits');
        }

        $this->key = $key;
        $this->keyId = $keyId;
    }

    /**
     * @param string $audience the GAITH chatbot id the proof is for; GAITH refuses it for any other chatbot
     * @param int|null $now Unix time, for tests; defaults to the current time
     */
    public function sign(ProductUser $user, string $audience, ?int $now = null): string
    {
        if (trim($audience) === '') {
            throw new \InvalidArgumentException('proof audience (the chatbot id) must not be empty');
        }
        $now = $now ?? time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->keyId];
        $claims = [
            'iss' => $user->product(),
            'sub' => $user->person(),
            'tenant' => $user->customer(),
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + self::LIFETIME_SECONDS,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $signed = self::encode($header) . '.' . self::encode($claims);
        if (!openssl_sign($signed, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('could not sign the identity proof');
        }

        return $signed . '.' . self::base64url($signature);
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    /** Keeps the key out of var_dump/print_r, e.g. in an error page. */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId];
    }

    private static function encode(array $data): string
    {
        return self::base64url(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
