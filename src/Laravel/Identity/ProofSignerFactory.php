<?php

namespace Osos\Gaith\Sdk\Laravel\Identity;

use Osos\Gaith\Sdk\Identity\ProofSigner;

/**
 * Builds the ProofSigner from `gaith-chatbot.proof`. Messages name the setting at fault,
 * never the key itself.
 */
final class ProofSignerFactory
{
    /**
     * @param array<string, mixed> $settings
     * @throws \InvalidArgumentException when the key or its id is missing or unusable
     */
    public static function make(array $settings): ProofSigner
    {
        $keyId = $settings['key_id'] ?? null;
        if (!is_string($keyId) || trim($keyId) === '') {
            throw new \InvalidArgumentException('gaith-chatbot.proof.key_id is not set (GAITH_PROOF_KEY_ID)');
        }

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
            // A PEM in .env is usually written on one line, with \n for its line breaks.
            $pem = str_replace('\n', "\n", $pem);
        }

        return new ProofSigner($pem, $keyId);
    }

    /** @param mixed $value */
    private static function filled($value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
