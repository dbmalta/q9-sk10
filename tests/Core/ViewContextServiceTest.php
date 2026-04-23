<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\ViewContext;
use App\Core\ViewContextService;
use PHPUnit\Framework\TestCase;

class ViewContextServiceTest extends TestCase
{
    /** @var array<string, mixed> Session backing store */
    private array $sessionStore;

    /** @var Session&\PHPUnit\Framework\MockObject\MockObject */
    private $session;

    /** @var Database&\PHPUnit\Framework\MockObject\MockObject */
    private $db;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->sessionStore = [];
        $this->session = $this->createMock(Session::class);
        $this->db = $this->createMock(Database::class);

        $this->session->method('get')->willReturnCallback(
            fn(string $k, mixed $d = null) => $this->sessionStore[$k] ?? $d
        );
        $this->session->method('set')->willReturnCallback(
            function (string $k, mixed $v): void {
                $this->sessionStore[$k] = $v;
                $_SESSION[$k] = $v;
            }
        );
        $this->session->method('remove')->willReturnCallback(
            function (string $k): void {
                unset($this->sessionStore[$k], $_SESSION[$k]);
            }
        );
    }

    /**
     * Make the Database mock answer the user-scopes query and members lookup.
     *
     * @param array<int, array{node_id:int, leaf:string, path:?string}> $scopes
     */
    private function stubScopes(array $scopes, bool $hasMemberRecord = false): void
    {
        $this->db->method('fetchAll')->willReturn(array_map(
            fn(array $s) => ['node_id' => $s['node_id'], 'leaf' => $s['leaf'], 'path' => $s['path'] ?? $s['leaf']],
            $scopes
        ));
        $this->db->method('fetchOne')->willReturn($hasMemberRecord ? ['1' => 1] : null);
    }

    private function makeUser(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'email' => 'user@example.test',
            'is_super_admin' => 0,
            'view_mode_last' => null,
            'scope_node_id_last' => null,
        ], $overrides);
    }

    private function makeRequest(array $query = []): Request
    {
        return new Request('GET', '/', $query, []);
    }

    // --- Unauthenticated -----------------------------------------------------

    public function testUnauthenticatedGetsNeutralMemberContext(): void
    {
        $this->session->method('getUser')->willReturn(null);
        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest());

        $this->assertFalse($ctx->canSwitchToAdmin);
        $this->assertFalse($ctx->canSwitchToMember);
        $this->assertSame(ViewContext::MODE_MEMBER, $ctx->mode);
    }

    // --- Mode resolution -----------------------------------------------------

    public function testUrlModeOverridesSession(): void
    {
        $this->session->method('getUser')->willReturn($this->makeUser());
        $this->sessionStore['view_mode'] = ViewContext::MODE_MEMBER;
        $this->stubScopes([['node_id' => 4, 'leaf' => 'A', 'path' => null]], hasMemberRecord: true);

        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest(['mode' => 'admin']));

        $this->assertSame(ViewContext::MODE_ADMIN, $ctx->mode);
    }

    public function testSessionModeUsedWhenNoUrlParam(): void
    {
        $this->session->method('getUser')->willReturn($this->makeUser());
        $this->sessionStore['view_mode'] = ViewContext::MODE_MEMBER;
        $this->stubScopes([['node_id' => 4, 'leaf' => 'A', 'path' => null]], hasMemberRecord: true);

        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest());

        $this->assertSame(ViewContext::MODE_MEMBER, $ctx->mode);
    }

    public function testUserDefaultUsedAsFallback(): void
    {
        $this->session->method('getUser')->willReturn(
            $this->makeUser(['view_mode_last' => ViewContext::MODE_MEMBER])
        );
        $this->stubScopes([['node_id' => 4, 'leaf' => 'A', 'path' => null]], hasMemberRecord: true);

        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest());

        $this->assertSame(ViewContext::MODE_MEMBER, $ctx->mode);
    }

    public function testModeFallbackRespectsCapabilities(): void
    {
        // User has no member record → can't be coerced into member mode.
        $this->session->method('getUser')->willReturn(
            $this->makeUser(['view_mode_last' => ViewContext::MODE_MEMBER])
        );
        $this->stubScopes([['node_id' => 4, 'leaf' => 'A', 'path' => null]], hasMemberRecord: false);

        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest());

        $this->assertSame(ViewContext::MODE_ADMIN, $ctx->mode);
    }

    // --- Scope resolution ----------------------------------------------------

    public function testSingleScopeAutoSelected(): void
    {
        $this->session->method('getUser')->willReturn($this->makeUser());
        $this->stubScopes([['node_id' => 7, 'leaf' => 'Only', 'path' => null]]);

        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest());

        $this->assertSame(7, $ctx->activeScopeNodeId);
    }

    public function testUrlScopeAllOverridesStored(): void
    {
        $this->session->method('getUser')->willReturn(
            $this->makeUser(['scope_node_id_last' => 4])
        );
        $this->stubScopes([
            ['node_id' => 4, 'leaf' => 'A', 'path' => null],
            ['node_id' => 5, 'leaf' => 'B', 'path' => null],
        ]);

        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest(['scope' => 'all']));

        $this->assertNull($ctx->activeScopeNodeId);
    }

    public function testInvalidUrlScopeFallsThrough(): void
    {
        $this->session->method('getUser')->willReturn(
            $this->makeUser(['scope_node_id_last' => 4])
        );
        $this->stubScopes([
            ['node_id' => 4, 'leaf' => 'A', 'path' => null],
            ['node_id' => 5, 'leaf' => 'B', 'path' => null],
        ]);

        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest(['scope' => '9999']));

        // User default (4) takes over when URL scope is invalid.
        $this->assertSame(4, $ctx->activeScopeNodeId);
    }

    public function testStaleStoredScopeFallsBackToAllNodes(): void
    {
        $this->session->method('getUser')->willReturn($this->makeUser());
        $this->sessionStore['active_scope_node_id'] = 999;
        $_SESSION['active_scope_node_id'] = 999;
        $this->stubScopes([
            ['node_id' => 4, 'leaf' => 'A', 'path' => null],
            ['node_id' => 5, 'leaf' => 'B', 'path' => null],
        ]);

        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest());

        $this->assertNull($ctx->activeScopeNodeId);
    }

    public function testMemberModeHasNoActiveScope(): void
    {
        $this->session->method('getUser')->willReturn($this->makeUser());
        $this->stubScopes([
            ['node_id' => 4, 'leaf' => 'A', 'path' => null],
            ['node_id' => 5, 'leaf' => 'B', 'path' => null],
        ], hasMemberRecord: true);

        $svc = new ViewContextService($this->db, $this->session);
        $ctx = $svc->resolve($this->makeRequest(['mode' => 'member']));

        $this->assertSame(ViewContext::MODE_MEMBER, $ctx->mode);
        $this->assertNull($ctx->activeScopeNodeId);
    }

    // --- Mutations -----------------------------------------------------------

    public function testSetModeRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $svc = new ViewContextService($this->db, $this->session);
        $svc->setMode(1, 'bogus');
    }

    public function testSetModePersistsToSessionAndUsers(): void
    {
        $this->db->expects($this->once())
            ->method('update')
            ->with('users', ['view_mode_last' => 'admin'], ['id' => 42]);

        $svc = new ViewContextService($this->db, $this->session);
        $svc->setMode(42, 'admin');

        $this->assertSame('admin', $this->sessionStore['view_mode']);
    }

    public function testSetScopeRejectsUnassignedNode(): void
    {
        $this->stubScopes([['node_id' => 4, 'leaf' => 'A', 'path' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $svc = new ViewContextService($this->db, $this->session);
        $svc->setScope(42, 999);
    }

    public function testSetScopeAllPersistsNull(): void
    {
        $this->db->expects($this->once())
            ->method('update')
            ->with('users', ['scope_node_id_last' => null], ['id' => 42]);

        $svc = new ViewContextService($this->db, $this->session);
        $svc->setScope(42, null);

        $this->assertNull($this->sessionStore['active_scope_node_id']);
    }
}
