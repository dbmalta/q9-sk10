<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Encryption;
use PHPUnit\Framework\TestCase;

class EncryptionTest extends TestCase
{
    private string $keyFile;

    protected function setUp(): void
    {
        $this->keyFile = ROOT_PATH . '/tests/fixtures/test_encryption.key';
        $dir = dirname($this->keyFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->keyFile, Encryption::generateKey());
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
        $plaintext = 'Sensitive medical data';

        $encrypted = $enc->encrypt($plaintext);
        $this->assertNotSame($plaintext, $encrypted);

        $decrypted = $enc->decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptProducesDifferentOutputEachTime(): void
    {
        $enc = new Encryption($this->keyFile);
        $plaintext = 'Same input';

        $a = $enc->encrypt($plaintext);
        $b = $enc->encrypt($plaintext);

        $this->assertNotSame($a, $b); // Random IV ensures different output
        $this->assertSame($plaintext, $enc->decrypt($a));
        $this->assertSame($plaintext, $enc->decrypt($b));
    }

    public function testEncryptDecryptVariedLengths(): void
    {
        $enc = new Encryption($this->keyFile);

        $tests = [
            '',
            'a',
            str_repeat('x', 10),
            str_repeat('y', 1000),
            str_repeat('z', 10000),
            "Unicode: áéíóú ñ 日本語 emoji: 🏕️",
        ];

        foreach ($tests as $plaintext) {
            $encrypted = $enc->encrypt($plaintext);
            $this->assertSame($plaintext, $enc->decrypt($encrypted));
        }
    }

    public function testDecryptFailsWithTamperedData(): void
    {
        $enc = new Encryption($this->keyFile);
        $encrypted = $enc->encrypt('test');

        // Tamper with the encrypted data
        $decoded = base64_decode($encrypted);
        $tampered = base64_encode($decoded . 'x');

        $this->expectException(\RuntimeException::class);
        $enc->decrypt($tampered);
    }

    public function testDecryptFailsWithInvalidBase64(): void
    {
        $enc = new Encryption($this->keyFile);

        $this->expectException(\RuntimeException::class);
        $enc->decrypt('not-valid-base64!!!');
    }

    public function testConstructorThrowsForMissingKeyFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        new Encryption('/nonexistent/key.file');
    }

    public function testConstructorThrowsForShortKey(): void
    {
        $shortKeyFile = ROOT_PATH . '/tests/fixtures/short_key.key';
        file_put_contents($shortKeyFile, 'too-short');

        try {
            $this->expectException(\RuntimeException::class);
            new Encryption($shortKeyFile);
        } finally {
            unlink($shortKeyFile);
        }
    }

    public function testGenerateKeyReturns32Bytes(): void
    {
        $key = Encryption::generateKey();
        $this->assertSame(32, strlen($key));
    }

    public function testGenerateKeyIsRandom(): void
    {
        $a = Encryption::generateKey();
        $b = Encryption::generateKey();
        $this->assertNotSame($a, $b);
    }
}
