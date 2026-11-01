<?php

namespace Osos\Gaith\Sdk\Identity;

/**
 * Creates the "identity proof" that goes to GAITH with every request.
 *
 * The proof is a small signed note (a JWT) that says: "this request comes from employee 42
 * at customer 7 of QHSE, and it is meant for chatbot X". It is signed with the product's
 * private key. GAITH has the matching public key, so it can check that the note really
 * came from the product and was not changed on the way.
 *
 * Each proof is valid for 2 minutes and has a random id, so GAITH can refuse it if
 * someone tries to send the same proof twice. The SDK makes a new proof for every request.
 *
 * This class only creates proofs. Checking them is GAITH's job.
 */
final class ProofSigner
{
    // How long a proof stays valid. GAITH also allows up to 60 seconds of clock difference.
    public const LIFETIME_SECONDS = 120;

    // Shorter RSA keys are too weak to trust.
    private const MIN_RSA_BITS = 2048;

    /**
     * The loaded private key. We keep OpenSSL's key object rather than the key text, so the
     * key itself does not show up if this object is ever printed in a log or error page.
     *
     * @var \OpenSSLAsymmetricKey|resource
     */
    private $key;

    /** @var string */
    private $keyId;

    /**
     * @param string $privateKeyPem the product's private key, as PEM text (RSA, 2048 bits or more, no password)
     * @param string $keyId the name GAITH knows this key by, e.g. "qhse-prod-1"
     * @throws \InvalidArgumentException when the key cannot be used. The message never includes the key.
     */
    public function __construct(string $privateKeyPem, string $keyId)
    {
        $keyId = trim($keyId);
        if ($keyId === '') {
            throw new \InvalidArgumentException('proof key id must not be empty');
        }

        $key = @openssl_pkey_get_private($privateKeyPem);
        // OpenSSL keeps its errors in a queue. Clear it so these errors do not show up
        // later in some unrelated OpenSSL call.
        while (openssl_error_string() !== false) {
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
     * Creates a new proof for this user.
     *
     * @param string $audience the id of the GAITH chatbot this request goes to. GAITH refuses the
     *                         proof if it arrives at any other chatbot.
     * @param int|null $now the current time; only tests pass this
     */
    public function sign(ProductUser $user, string $audience, ?int $now = null): string
    {
        if (trim($audience) === '') {
            throw new \InvalidArgumentException('proof audience (the chatbot id) must not be empty');
        }
        $now = $now ?? time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->keyId];
        $claims = [
            'iss' => $user->product(),         // which product sent it
            'sub' => $user->person(),          // who is chatting
            'tenant' => $user->customer(),     // which customer they belong to
            'aud' => $audience,                // which chatbot it is for
            'iat' => $now,                     // when it was made
            'exp' => $now + self::LIFETIME_SECONDS, // when it stops being valid
            'jti' => bin2hex(random_bytes(16)), // random id, so it can only be used once
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

    /** What PHP shows if this object is dumped: only the key's name, never the key. */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId];
    }

    private static function encode(array $data): string
    {
        return self::base64url(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    // JWTs use a URL-safe version of base64 without "=" padding.
    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
