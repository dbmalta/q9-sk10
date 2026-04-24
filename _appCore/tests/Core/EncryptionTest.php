<?php

declare(strict_types=1);

namespace Tests\Core;

use AppCore\Core\Encryption;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test — no database, no config. The Encryption class reads its
 * key from a file, so the test writes a temp key and cleans up after.
 */
class EncryptionTest extends TestCase
{
    private string $keyFile;

    protected function setUp(): void
    {
        $this->keyFile = tempnam(sys_get_temp_dir(), 'appcore_key_');
        file_put_contents($this->keyFile, random_bytes(32));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->keyFile)) {
            unlink($this->keyFile);
        }
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $enc = new Encryption($this->keyFile);
        $plaintext = 'hello world';

        $ciphertext = $enc->encrypt($plaintext);
        $this->assertNotSame($plaintext, $ciphertext);
        $this->assertSame($plaintext, $enc->decrypt($ciphertext));
    }

    public function testTamperedCiphertextFailsDecryption(): void
    {
        $enc = new Encryption($this->keyFile);
        $ciphertext = $enc->encrypt('secret');

        // Flip a byte in the payload
        $decoded = base64_decode($ciphertext);
        $decoded[20] = $decoded[20] === 'A' ? 'B' : 'A';
        $tampered = base64_encode($decoded);

        $this->expectException(\RuntimeException::class);
        $enc->decrypt($tampered);
    }

    public function testMissingKeyFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        new Encryption('/no/such/file');
    }
}
