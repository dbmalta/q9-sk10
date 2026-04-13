<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Csrf;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

class CsrfTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        // Use a mock session that stores data in an array instead of PHP sessions
        $this->session = $this->createMock(Session::class);
    }

    public function testGetTokenGeneratesTokenWhenMissing(): void
    {
        $stored = null;

        $this->session->method('get')
            ->with('_csrf_token')
            ->willReturnCallback(function () use (&$stored) {
                return $stored;
            });

        $this->session->method('set')
            ->willReturnCallback(function ($key, $value) use (&$stored) {
                $stored = $value;
            });

        $csrf = new Csrf($this->session);
        $token = $csrf->getToken();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // 32 bytes hex-encoded
    }

    public function testGetTokenReturnsSameTokenOnSubsequentCalls(): void
    {
        $stored = 'existing-token';

        $this->session->method('get')
            ->with('_csrf_token')
            ->willReturn($stored);

        $csrf = new Csrf($this->session);
        $this->assertSame('existing-token', $csrf->getToken());
    }

    public function testValidateTokenWithCorrectToken(): void
    {
        $this->session->method('get')
            ->with('_csrf_token')
            ->willReturn('valid-token');

        $csrf = new Csrf($this->session);
        $this->assertTrue($csrf->validateToken('valid-token'));
    }

    public function testValidateTokenRejectsWrongToken(): void
    {
        $this->session->method('get')
            ->with('_csrf_token')
            ->willReturn('valid-token');

        $csrf = new Csrf($this->session);
        $this->assertFalse($csrf->validateToken('wrong-token'));
    }

    public function testValidateTokenRejectsEmptyToken(): void
    {
        $this->session->method('get')
            ->with('_csrf_token')
            ->willReturn('valid-token');

        $csrf = new Csrf($this->session);
        $this->assertFalse($csrf->validateToken(''));
    }

    public function testValidateTokenRejectsWhenNoSessionToken(): void
    {
        $this->session->method('get')
            ->with('_csrf_token')
            ->willReturn(null);

        $csrf = new Csrf($this->session);
        $this->assertFalse($csrf->validateToken('any-token'));
    }

    public function testFieldReturnsHiddenInput(): void
    {
        $this->session->method('get')
            ->with('_csrf_token')
            ->willReturn('test-token');

        $csrf = new Csrf($this->session);
        $field = $csrf->field();

        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="_csrf_token"', $field);
        $this->assertStringContainsString('value="test-token"', $field);
    }
}
