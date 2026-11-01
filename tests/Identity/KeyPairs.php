<?php

namespace Osos\Gaith\Sdk\Tests\Identity;

/**
 * Makes throwaway key pairs while the tests run, so no private key is ever saved in the repo.
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
     * Making keys needs an OpenSSL settings file. PHP on Windows does not set one up by
     * default, so we use the copy that comes with PHP (next to php.exe) when none is set.
     */
    private static function config(): array
    {
        $bundled = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';

        return getenv('OPENSSL_CONF') === false && is_file($bundled) ? ['config' => $bundled] : [];
    }
}
