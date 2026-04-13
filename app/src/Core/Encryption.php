<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application-layer encryption utility.
 *
 * Used for encrypting sensitive data (medical details, MFA secrets)
 * at rest. Uses AES-256-GCM with a key stored outside the webroot
 * in /config/encryption.key.
 */
class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private string $key;

    /**
     * @param string $keyFilePath Path to the encryption key file
     * @throws \RuntimeException if the key file is missing or unreadable
     */
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

    /**
     * Encrypt a plaintext string.
     *
     * @param string $plaintext The data to encrypt
     * @return string Base64-encoded string containing IV + auth tag + ciphertext
     */
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

    /**
     * Decrypt a previously encrypted string.
     *
     * @param string $encrypted The base64-encoded encrypted data
     * @return string The original plaintext
     * @throws \RuntimeException if decryption fails (e.g. tampered data)
     */
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
     * Generate a new encryption key (used by the setup wizard).
     *
     * @return string 32 random bytes suitable for AES-256
     */
    public static function generateKey(): string
    {
        return random_bytes(32);
    }
}
