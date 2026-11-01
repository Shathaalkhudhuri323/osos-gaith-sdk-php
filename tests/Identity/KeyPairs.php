<?php

namespace Osos\Gaith\Sdk\Tests\Identity;

/**
 * Throwaway key pairs made at test time, so no private key is ever committed.
 */
final class KeyPairs
{
    /** @return array{private: string, public: string} */
    public static function rsa(int $bits): array
    {
        return self::make(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
    }

    /** @return array{private: string, public: string} */
    public static function ec(): array
    {
        return self::make(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    }

    /** @return array{private: string, public: string} */
    private static function make(array $options): array
    {
        $options += self::config();
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new \RuntimeException('could not generate a test key: ' . openssl_error_string());
        }
        openssl_pkey_export($key, $private, null, self::config());

        return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }

    /**
     * PHP on Windows ships no default OpenSSL config, which key generation needs; use
     * the copy bundled next to php.exe when OPENSSL_CONF is not set.
     */
    private static function config(): array
    {
        $bundled = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';

        return getenv('OPENSSL_CONF') === false && is_file($bundled) ? ['config' => $bundled] : [];
    }
}
