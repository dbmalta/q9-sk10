<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Application-layer AES-256-GCM encryption.
 *
 * Used for encrypting PII and credentials at rest. The key is loaded from
 * a file outside the webroot (default /config/encryption.key, chmod 0600).
 */
class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct(string $keyFilePath)
    {
        if (!file_exists($keyFilePath) || !is_readable($keyFilePath)) {
            throw new \RuntimeException("Encryption key file not found or not readable: $keyFilePath");
        }

        $key = file_get_contents($keyFilePath);
        if ($key === false || strlen($key) < 32) {
            throw new \RuntimeException('Encryption key is invalid (must be at least 32 bytes)');
        }

        $this->key = substr($key, 0, 32);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted, true);
        if ($data === false) {
            throw new \RuntimeException('Decryption failed: invalid base64');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($data) < $ivLength + self::TAG_LENGTH) {
            throw new \RuntimeException('Decryption failed: data too short');
        }

        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($data, $ivLength + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: data may have been tampered with');
        }

        return $plaintext;
    }

    /**
     * Generate 32 random bytes suitable for AES-256. Used by the setup wizard.
     */
    public static function generateKey(): string
    {
        return random_bytes(32);
    }
}
