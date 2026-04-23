<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use App\Core\Database;
use App\Modules\Members\Services\RegistrationService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies getPendingRegistrations filters by the supplied scope node-ids.
 * The controller passes the subtree-expanded list from MemberService, so
 * this test exercises the filter shape the admin UI relies on.
 */
class RegistrationScopingTest extends TestCase
{
    private Database $db;
    private int $nodeA;
    private int $nodeB;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['member_nodes', 'members', 'org_nodes'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `members` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `membership_number` VARCHAR(20) NOT NULL,
            `first_name` VARCHAR(100) NOT NULL,
            `surname` VARCHAR(100) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `user_id` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `member_nodes` (
            `member_id` INT UNSIGNED NOT NULL,
            `node_id` INT UNSIGNED NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`member_id`, `node_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->nodeA = $this->db->insert('org_nodes', ['name' => 'Node A']);
        $this->nodeB = $this->db->insert('org_nodes', ['name' => 'Node B']);

        // Three pending members, assigned to A, B, and neither respectively.
        $this->seedPending('Alice', $this->nodeA);
        $this->seedPending('Bob',   $this->nodeB);
        $this->seedPending('Eve',   null);
        // One approved member in A (should never appear in pending queries).
        $this->db->insert('members', [
            'membership_number' => 'A-done', 'first_name' => 'Already', 'surname' => 'Approved',
            'status' => 'active',
        ]);
    }

    private function seedPending(string $name, ?int $nodeId): void
    {
        $id = $this->db->insert('members', [
            'membership_number' => 'P-' . $name,
            'first_name' => $name, 'surname' => 'Test', 'status' => 'pending',
        ]);
        if ($nodeId !== null) {
            $this->db->insert('member_nodes', [
                'member_id' => $id, 'node_id' => $nodeId, 'is_primary' => 1,
            ]);
        }
    }

    public function testUnscopedReturnsAllPendingIncludingOrphans(): void
    {
        $svc = new RegistrationService($this->db);
        $rows = $svc->getPendingRegistrations();
        $names = array_column($rows, 'first_name');
        sort($names);
        $this->assertSame(['Alice', 'Bob', 'Eve'], $names);
        $this->assertNotContains('Already', $names);
    }

    public function testScopedToNodeAReturnsOnlyAlice(): void
    {
        $svc = new RegistrationService($this->db);
        $rows = $svc->getPendingRegistrations([$this->nodeA]);
        $names = array_column($rows, 'first_name');
        $this->assertSame(['Alice'], $names);
    }

    public function testScopedToMultipleNodesReturnsUnion(): void
    {
        $svc = new RegistrationService($this->db);
        $rows = $svc->getPendingRegistrations([$this->nodeA, $this->nodeB]);
        $names = array_column($rows, 'first_name');
        sort($names);
        // Eve has no node_id → filtered out when scope is non-empty.
        $this->assertSame(['Alice', 'Bob'], $names);
    }
}
