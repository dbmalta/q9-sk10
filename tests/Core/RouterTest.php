<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testGenerateUrlWithParams(): void
    {
        $router = new Router();
        $router->get('/members/{id:\d+}', ['MembersController', 'view'], 'members.view');

        $url = $router->generateUrl('members.view', ['id' => 42]);
        $this->assertSame('/members/42', $url);
    }

    public function testGenerateUrlWithoutParams(): void
    {
        $router = new Router();
        $router->get('/dashboard', ['DashboardController', 'index'], 'dashboard');

        $url = $router->generateUrl('dashboard');
        $this->assertSame('/dashboard', $url);
    }

    public function testGenerateUrlThrowsForUnknownRoute(): void
    {
        $router = new Router();

        $this->expectException(\InvalidArgumentException::class);
        $router->generateUrl('nonexistent');
    }

    public function testGenerateUrlThrowsForMissingParam(): void
    {
        $router = new Router();
        $router->get('/members/{id:\d+}', ['MembersController', 'view'], 'members.view');

        $this->expectException(\InvalidArgumentException::class);
        $router->generateUrl('members.view');
    }

    public function testAddRouteWithAllMethods(): void
    {
        $router = new Router();

        // These should not throw
        $router->get('/test', ['C', 'm']);
        $router->post('/test', ['C', 'm']);
        $router->put('/test', ['C', 'm']);
        $router->delete('/test', ['C', 'm']);

        $this->assertTrue(true); // No exception = pass
    }
}
