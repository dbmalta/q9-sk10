<?php

declare(strict_types=1);

namespace Tests\Core;

use AppCore\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for URL generation. Dispatch is not exercised here
 * because it instantiates controllers which depend on the Application
 * singleton — integration tests cover that path.
 */
class RouterTest extends TestCase
{
    public function testGeneratesSimpleUrl(): void
    {
        $router = new Router();
        $router->get('/admin/dashboard', ['Foo', 'index'], 'admin.dashboard');

        $this->assertSame('/admin/dashboard', $router->generateUrl('admin.dashboard'));
    }

    public function testSubstitutesPlaceholders(): void
    {
        $router = new Router();
        $router->get('/users/{id:\d+}/edit', ['Foo', 'edit'], 'users.edit');

        $this->assertSame('/users/42/edit', $router->generateUrl('users.edit', ['id' => 42]));
    }

    public function testThrowsOnUnknownRoute(): void
    {
        $router = new Router();
        $this->expectException(\InvalidArgumentException::class);
        $router->generateUrl('does.not.exist');
    }

    public function testThrowsOnMissingParameter(): void
    {
        $router = new Router();
        $router->get('/users/{id:\d+}', ['Foo', 'view'], 'users.view');

        $this->expectException(\InvalidArgumentException::class);
        $router->generateUrl('users.view');
    }
}
