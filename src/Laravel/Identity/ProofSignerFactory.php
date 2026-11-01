<?php

namespace Osos\Gaith\Sdk\Laravel\Identity;

use Osos\Gaith\Sdk\Identity\ProofSigner;

/**
 * Creates the ProofSigner from the product's settings (`gaith-chatbot.proof`, filled from
 * the GAITH_PROOF_* values in .env).
 *
 * The private key can be given in one of two ways:
 *   - GAITH_PROOF_PRIVATE_KEY_PATH: the path of a file that holds the key, or
 *   - GAITH_PROOF_PRIVATE_KEY: the key text itself.
 *
 * If something is wrong, the error message says which setting to fix. It never includes
 * the key itself, so the key cannot leak into a log.
 */
final class ProofSignerFactory
{
    /**
     * @param array<string, mixed> $settings
     * @throws \InvalidArgumentException when the key or its name is missing or cannot be used
     */
    public static function make(array $settings): ProofSigner
    {
        $keyId = $settings['key_id'] ?? null;
        if (!is_string($keyId) || trim($keyId) === '') {
            throw new \InvalidArgumentException('gaith-chatbot.proof.key_id is not set (GAITH_PROOF_KEY_ID)');
        }

        // Exactly one of the two ways must be used. Both at once is confusing, so we refuse it.
        $pem = self::filled($settings['private_key'] ?? null);
        $path = self::filled($settings['private_key_path'] ?? null);
        if (($pem === null) === ($path === null)) {
            throw new \InvalidArgumentException(
                'set exactly one of gaith-chatbot.proof.private_key (GAITH_PROOF_PRIVATE_KEY) '
                . 'and gaith-chatbot.proof.private_key_path (GAITH_PROOF_PRIVATE_KEY_PATH)'
            );
        }

        if ($path !== null) {
            $pem = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            if ($pem === false) {
                throw new \InvalidArgumentException("gaith-chatbot.proof.private_key_path cannot be read: {$path}");
            }
        } else {
            // In .env the key is usually written on one line, with the two characters \n
            // where each line break should be. Turn those back into real line breaks.
            $pem = str_replace('\n', "\n", $pem);
        }

        return new ProofSigner($pem, $keyId);
    }

    /** Returns the value without surrounding spaces, or null if it is empty or not text. */
    private static function filled($value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
