<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use App\Core\PermissionResolver;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

/**
 * PermissionResolver tests — require a running MySQL test database.
 *
 * Tests cover single/multiple assignments, permission union, expired
 * assignment handling, scope filtering, special flags, super admin
 * bypass, and the no-assignments case.
 */
class PermissionResolverTest extends TestCase
{
    private ?Database $db = null;
    private ?PermissionResolver $resolver = null;

    /** Mock session storage */
    private array $sessionData = [];

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Create tables
        $this->db->query("DROP TABLE IF EXISTS `member_attachments`");
        $this->db->query("DROP TABLE IF EXISTS `member_timeline`");
        $this->db->query("DROP TABLE IF EXISTS `medical_access_log`");
        $this->db->query("DROP TABLE IF EXISTS `member_pending_changes`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignment_scopes`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignments`");
        $this->db->query("DROP TABLE IF EXISTS `roles`");
        $this->db->query("DROP TABLE IF EXISTS `password_resets`");
        $this->db->query("DROP TABLE IF EXISTS `users`");

        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `mfa_secret` TEXT NULL,
                `mfa_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `last_login_at` DATETIME NULL,
                `password_changed_at` DATETIME NULL,
                `failed_login_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `locked_until` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `roles` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL UNIQUE,
                `description` VARCHAR(500) NULL,
                `permissions` JSON NOT NULL DEFAULT ('{}'),
                `can_publish_events` TINYINT(1) NOT NULL DEFAULT 0,
                `can_access_medical` TINYINT(1) NOT NULL DEFAULT 0,
                `can_access_financial` TINYINT(1) NOT NULL DEFAULT 0,
                `is_system` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `role_assignments` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `role_id` INT UNSIGNED NOT NULL,
                `context_type` ENUM('node', 'team') NULL,
                `context_id` INT UNSIGNED NULL,
                `start_date` DATE NOT NULL,
                `end_date` DATE NULL,
                `assigned_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_prt_ra_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_prt_ra_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `role_assignment_scopes` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `assignment_id` INT UNSIGNED NOT NULL,
                `node_id` INT UNSIGNED NOT NULL,
                CONSTRAINT `fk_prt_scope_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `role_assignments`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->query("DROP TABLE IF EXISTS `role_assignment_scopes`");
            $this->db->query("DROP TABLE IF EXISTS `role_assignments`");
            $this->db->query("DROP TABLE IF EXISTS `roles`");
            $this->db->query("DROP TABLE IF EXISTS `password_resets`");
            $this->db->query("DROP TABLE IF EXISTS `users`");
        }
    }

    /**
     * Create a mock session that stores data in an array.
     */
    private function createMockSession(): Session
    {
        $this->sessionData = [];
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(function (string $key, mixed $default = null) {
            return $this->sessionData[$key] ?? $default;
        });
        $session->method('set')->willReturnCallback(function (string $key, mixed $value) {
            $this->sessionData[$key] = $value;
        });
        $session->method('remove')->willReturnCallback(function (string $key) {
            unset($this->sessionData[$key]);
        });
        return $session;
    }

    private function createUser(bool $isSuperAdmin = false): int
    {
        return $this->db->insert('users', [
            'email' => 'user' . uniqid() . '@example.com',
            'password_hash' => 'dummy',
            'is_super_admin' => $isSuperAdmin ? 1 : 0,
        ]);
    }

    private function createRole(string $name, array $permissions = [], array $flags = []): int
    {
        return $this->db->insert('roles', [
            'name' => $name,
            'permissions' => json_encode($permissions),
            'can_publish_events' => ($flags['can_publish_events'] ?? false) ? 1 : 0,
            'can_access_medical' => ($flags['can_access_medical'] ?? false) ? 1 : 0,
            'can_access_financial' => ($flags['can_access_financial'] ?? false) ? 1 : 0,
        ]);
    }

    private function assignRole(int $userId, int $roleId, ?string $endDate = null, array $scopeNodeIds = []): int
    {
        $assignmentId = $this->db->insert('role_assignments', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'start_date' => '2026-01-01',
            'end_date' => $endDate,
        ]);

        foreach ($scopeNodeIds as $nodeId) {
            $this->db->insert('role_assignment_scopes', [
                'assignment_id' => $assignmentId,
                'node_id' => $nodeId,
            ]);
        }

        return $assignmentId;
    }

    // ──── Single assignment ────

    public function testSingleAssignmentGrantsPermissions(): void
    {
        $userId = $this->createUser();
        $roleId = $this->createRole('Editor', ['members.read' => true, 'members.write' => true]);
        $this->assignRole($userId, $roleId);

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertTrue($resolver->can('members.read'));
        $this->assertTrue($resolver->can('members.write'));
        $this->assertFalse($resolver->can('events.read'));
    }

    // ──── Multiple assignments — union ────

    public function testMultipleAssignmentsUnionPermissions(): void
    {
        $userId = $this->createUser();
        $role1 = $this->createRole('Role A', ['members.read' => true]);
        $role2 = $this->createRole('Role B', ['events.read' => true, 'events.write' => true]);
        $this->assignRole($userId, $role1);
        $this->assignRole($userId, $role2);

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertTrue($resolver->can('members.read'));
        $this->assertTrue($resolver->can('events.read'));
        $this->assertTrue($resolver->can('events.write'));
        $this->assertFalse($resolver->can('settings.write'));
    }

    // ──── Expired assignments ────

    public function testExpiredAssignmentIgnored(): void
    {
        $userId = $this->createUser();
        $roleId = $this->createRole('Old Role', ['members.read' => true]);
        $this->assignRole($userId, $roleId, '2025-01-01'); // expired

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertFalse($resolver->can('members.read'));
    }

    public function testFutureEndDateStillActive(): void
    {
        $userId = $this->createUser();
        $roleId = $this->createRole('Future Role', ['members.read' => true]);
        $this->assignRole($userId, $roleId, '2099-12-31');

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertTrue($resolver->can('members.read'));
    }

    // ──── No assignments ────

    public function testNoAssignmentsMeansNoPermissions(): void
    {
        $userId = $this->createUser();

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertFalse($resolver->can('members.read'));
        $this->assertFalse($resolver->canPublishEvents());
        $this->assertFalse($resolver->canAccessMedical());
        $this->assertEmpty($resolver->getActiveAssignments());
    }

    // ──── Super admin ────

    public function testSuperAdminHasAllPermissions(): void
    {
        $userId = $this->createUser(true);

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertTrue($resolver->isSuperAdmin());
        $this->assertTrue($resolver->can('members.read'));
        $this->assertTrue($resolver->can('anything.at.all'));
        $this->assertTrue($resolver->canPublishEvents());
        $this->assertTrue($resolver->canAccessMedical());
        $this->assertTrue($resolver->canAccessFinancial());
    }

    public function testSuperAdminCanAccessAnyNode(): void
    {
        $userId = $this->createUser(true);

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertTrue($resolver->canAccessNode(1));
        $this->assertTrue($resolver->canAccessNode(999));
        $this->assertEmpty($resolver->getScopeNodeIds()); // empty = unrestricted
    }

    // ──── Special flags ────

    public function testSpecialFlagsUnioned(): void
    {
        $userId = $this->createUser();
        $role1 = $this->createRole('With Events', [], ['can_publish_events' => true]);
        $role2 = $this->createRole('With Medical', [], ['can_access_medical' => true]);
        $this->assignRole($userId, $role1);
        $this->assignRole($userId, $role2);

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertTrue($resolver->canPublishEvents());
        $this->assertTrue($resolver->canAccessMedical());
        $this->assertFalse($resolver->canAccessFinancial());
    }

    // ──── Scope filtering ────

    public function testScopeNodeIdsUnioned(): void
    {
        $userId = $this->createUser();
        $role1 = $this->createRole('Scope A', ['members.read' => true]);
        $role2 = $this->createRole('Scope B', ['events.read' => true]);
        $this->assignRole($userId, $role1, null, [10, 20]);
        $this->assignRole($userId, $role2, null, [20, 30]);

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $scopeIds = $resolver->getScopeNodeIds();
        sort($scopeIds);
        $this->assertSame([10, 20, 30], $scopeIds);
    }

    public function testCanAccessNodeChecksScope(): void
    {
        $userId = $this->createUser();
        $roleId = $this->createRole('Scoped', ['members.read' => true]);
        $this->assignRole($userId, $roleId, null, [10, 20]);

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertTrue($resolver->canAccessNode(10));
        $this->assertTrue($resolver->canAccessNode(20));
        $this->assertFalse($resolver->canAccessNode(30));
    }

    public function testNoScopeNodesMeansNoAccess(): void
    {
        $userId = $this->createUser();
        $roleId = $this->createRole('No Scope', ['members.read' => true]);
        $this->assignRole($userId, $roleId, null, []);

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $this->assertFalse($resolver->canAccessNode(1));
    }

    // ──── Session caching ────

    public function testPermissionsCachedInSession(): void
    {
        $userId = $this->createUser();
        $roleId = $this->createRole('Cached', ['members.read' => true]);
        $this->assignRole($userId, $roleId);

        $session = $this->createMockSession();
        $resolver = new PermissionResolver($this->db, $session);
        $resolver->loadForUser($userId);

        // Session should now have cached data
        $this->assertNotNull($this->sessionData['_permissions'] ?? null);

        // Create a new resolver with the same session — should use cache
        $resolver2 = new PermissionResolver($this->db, $session);
        $resolver2->loadForUser($userId);
        $this->assertTrue($resolver2->can('members.read'));
    }

    public function testInvalidateClearsCache(): void
    {
        $userId = $this->createUser();
        $roleId = $this->createRole('Invalidate', ['members.read' => true]);
        $this->assignRole($userId, $roleId);

        $session = $this->createMockSession();
        $resolver = new PermissionResolver($this->db, $session);
        $resolver->loadForUser($userId);
        $this->assertTrue($resolver->can('members.read'));

        $resolver->invalidate();
        $this->assertFalse($resolver->can('members.read'));
        $this->assertNull($this->sessionData['_permissions'] ?? null);
    }

    // ──── Active assignments list ────

    public function testGetActiveAssignmentsReturnsRoleDetails(): void
    {
        $userId = $this->createUser();
        $roleId = $this->createRole('Viewer', ['members.read' => true]);
        $this->assignRole($userId, $roleId);

        $resolver = new PermissionResolver($this->db, $this->createMockSession());
        $resolver->loadForUser($userId);

        $assignments = $resolver->getActiveAssignments();
        $this->assertCount(1, $assignments);
        $this->assertSame('Viewer', $assignments[0]['role_name']);
    }
}
