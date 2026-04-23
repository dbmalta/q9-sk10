<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testDefaultStatusCode(): void
    {
        $response = new Response();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSetStatusCode(): void
    {
        $response = new Response();
        $response->setStatusCode(404);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testHtmlFactoryMethod(): void
    {
        $response = Response::html('<p>Hello</p>', 201);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('<p>Hello</p>', $response->getBody());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaders()['Content-Type']);
    }

    /**
     * Regression guard: after the pending-changes / approval-UI bug caused
     * by browser caching (POST → 302 → GET serving stale HTML), every
     * authenticated HTML response must set Cache-Control: no-store.
     */
    public function testHtmlFactorySetsNoStoreCacheControl(): void
    {
        $response = Response::html('<p>hi</p>');
        $this->assertSame('no-store, must-revalidate', $response->getHeaders()['Cache-Control'] ?? '');
    }

    public function testJsonFactoryMethod(): void
    {
        $response = Response::json(['name' => 'test']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"name": "test"', $response->getBody());
        $this->assertSame('application/json; charset=UTF-8', $response->getHeaders()['Content-Type']);
    }

    public function testRedirectFactoryMethod(): void
    {
        $response = Response::redirect('/dashboard');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeaders()['Location']);
    }

    public function testSetHeader(): void
    {
        $response = new Response();
        $response->setHeader('X-Custom', 'value');
        $this->assertSame('value', $response->getHeaders()['X-Custom']);
    }

    public function testSetBody(): void
    {
        $response = new Response();
        $response->setBody('content');
        $this->assertSame('content', $response->getBody());
    }

    public function testMethodChaining(): void
    {
        $response = (new Response())
            ->setStatusCode(201)
            ->setHeader('X-Test', 'yes')
            ->setBody('created');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaders()['X-Test']);
        $this->assertSame('created', $response->getBody());
    }
}
