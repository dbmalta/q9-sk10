<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testGetMethodReturnsUppercase(): void
    {
        $request = new Request('get', '/');
        $this->assertSame('GET', $request->getMethod());
    }

    public function testGetUriStripsQueryString(): void
    {
        $request = new Request('GET', '/members?page=2&sort=name');
        $this->assertSame('/members', $request->getUri());
    }

    public function testGetUriNormalisesSlashes(): void
    {
        $request = new Request('GET', '/members/');
        $this->assertSame('/members', $request->getUri());
    }

    public function testRootUriStaysAsSlash(): void
    {
        $request = new Request('GET', '/');
        $this->assertSame('/', $request->getUri());
    }

    public function testGetParamPostOverridesGet(): void
    {
        $request = new Request('POST', '/', ['key' => 'get_value'], ['key' => 'post_value']);
        $this->assertSame('post_value', $request->getParam('key'));
    }

    public function testGetParamReturnsDefaultWhenMissing(): void
    {
        $request = new Request('GET', '/');
        $this->assertNull($request->getParam('missing'));
        $this->assertSame('default', $request->getParam('missing', 'default'));
    }

    public function testIsStateChangingForPostPutDeletePatch(): void
    {
        $this->assertTrue((new Request('POST', '/'))->isStateChanging());
        $this->assertTrue((new Request('PUT', '/'))->isStateChanging());
        $this->assertTrue((new Request('DELETE', '/'))->isStateChanging());
        $this->assertTrue((new Request('PATCH', '/'))->isStateChanging());
        $this->assertFalse((new Request('GET', '/'))->isStateChanging());
    }

    public function testIsHtmxDetectsHeader(): void
    {
        $request = new Request('GET', '/', [], [], [], ['HX-REQUEST' => 'true']);
        $this->assertTrue($request->isHtmx());
    }

    public function testIsHtmxReturnsFalseWithoutHeader(): void
    {
        $request = new Request('GET', '/');
        $this->assertFalse($request->isHtmx());
    }

    public function testIsAjaxDetectsXmlHttpRequest(): void
    {
        $request = new Request('GET', '/', [], [], [], ['X-REQUESTED-WITH' => 'XMLHttpRequest']);
        $this->assertTrue($request->isAjax());
    }

    public function testIsAjaxDetectsHtmx(): void
    {
        $request = new Request('GET', '/', [], [], [], ['HX-REQUEST' => 'true']);
        $this->assertTrue($request->isAjax());
    }

    public function testGetAllParamsMergesPostOverGet(): void
    {
        $request = new Request('POST', '/',
            ['a' => '1', 'b' => '2'],
            ['b' => '3', 'c' => '4']
        );
        $params = $request->getAllParams();
        $this->assertSame('1', $params['a']);
        $this->assertSame('3', $params['b']); // POST overrides GET
        $this->assertSame('4', $params['c']);
    }

    public function testGetClientIp(): void
    {
        $request = new Request('GET', '/', [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $this->assertSame('192.168.1.1', $request->getClientIp());
    }

    public function testGetClientIpDefaultsToZero(): void
    {
        $request = new Request('GET', '/');
        $this->assertSame('0.0.0.0', $request->getClientIp());
    }
}
