<?php

namespace App\Services;

use RuntimeException;

class LinkCrypt
{
    private const CIPHER = 'AES-256-CBC';

    private readonly string $key;
    private readonly string $iv;

    public function __construct()
    {
        $keyHex = config('proxy.encryption_key', '');
        $ivHex  = config('proxy.iv', '');

        if (! $keyHex || ! $ivHex) {
            throw new RuntimeException('PROXY_ENCRYPTION_KEY and PROXY_IV must be set in .env');
        }

        $key = hex2bin($keyHex);
        $iv  = hex2bin($ivHex);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('PROXY_ENCRYPTION_KEY must be exactly 64 hex characters (32 bytes)');
        }

        if ($iv === false || strlen($iv) !== 16) {
            throw new RuntimeException('PROXY_IV must be exactly 32 hex characters (16 bytes)');
        }

        $this->key = $key;
        $this->iv  = $iv;
    }

    /**
     * Encrypt a URL into a URL-safe string.
     * Same input always produces the same output (deterministic, no TTL).
     */
    public function encrypt(string $url): string
    {
        $encrypted = openssl_encrypt($url, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $this->iv);

        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
    }

    /**
     * Decrypt a payload back to its original URL.
     */
    public function decrypt(string $payload): string
    {
        $padded = $payload . str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $raw    = base64_decode(strtr($padded, '-_', '+/'), strict: true);

        if ($raw === false) {
            throw new RuntimeException('Invalid payload encoding');
        }

        $url = openssl_decrypt($raw, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $this->iv);

        if ($url === false) {
            throw new RuntimeException('Decryption failed');
        }

        return $url;
    }
}
