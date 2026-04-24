<?php

declare(strict_types=1);

namespace Tests\Modules\OrgStructure;

use App\Core\Database;
use App\Modules\OrgStructure\Services\OrgService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the member-count rollup in OrgService::getTree().
 *
 * Exercises the closure-table rollup so a count at the root reflects
 * members assigned anywhere in the subtree, while also respecting:
 *   - members.status = 'active'
 *   - role_assignments currently in effect (start_date/end_date window)
 *
 * Each node in the returned tree carries:
 *   - member_count_direct  (assignments scoped directly to that node)
 *   - member_count_total   (assignments scoped to the node or any descendant)
 */
class OrgTreeMemberCountsTest extends TestCase
{
    private Database $db;
    private OrgService $service;

    /** @var array<string,int> */
    private array $nodes = [];

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach ([
            'role_assignment_scopes', 'role_assignments', 'org_teams',
            'org_closure', 'org_nodes', 'org_level_types',
            'members', 'users',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `members` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NULL,
            `first_name` VARCHAR(100) NOT NULL,
            `surname` VARCHAR(100) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `org_level_types` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `depth` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_leaf` TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `parent_id` INT UNSIGNED NULL,
            `level_type_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(200) NOT NULL,
            `short_name` VARCHAR(50) NULL,
            `description` TEXT NULL,
            `age_group_min` TINYINT UNSIGNED NULL,
            `age_group_max` TINYINT UNSIGNED NULL,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `org_closure` (
            `ancestor_id` INT UNSIGNED NOT NULL,
            `descendant_id` INT UNSIGNED NOT NULL,
            `depth` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ancestor_id`, `descendant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `org_teams` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `node_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(200) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `role_assignments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `role_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `start_date` DATE NOT NULL,
            `end_date` DATE NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `role_assignment_scopes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `assignment_id` INT UNSIGNED NOT NULL,
            `node_id` INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Level types — only thing OrgService::getTree cares about is lt.name and lt.is_leaf
        $this->db->insert('org_level_types', ['name' => 'Group', 'depth' => 0]);
        $this->db->insert('org_level_types', ['name' => 'Section', 'depth' => 1, 'is_leaf' => 1]);

        $this->service = new OrgService($this->db);

        // Tree: Group → Cubs, Scouts
        //       OtherGroup (empty)
        $this->nodes['group']       = $this->service->createNode(['name' => 'Group',       'level_type_id' => 1]);
        $this->nodes['cubs']        = $this->service->createNode(['name' => 'Cubs',        'parent_id' => $this->nodes['group'], 'level_type_id' => 2]);
        $this->nodes['scouts']      = $this->service->createNode(['name' => 'Scouts',      'parent_id' => $this->nodes['group'], 'level_type_id' => 2]);
        $this->nodes['other_group'] = $this->service->createNode(['name' => 'OtherGroup',  'level_type_id' => 1]);
    }

    protected function tearDown(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach ([
            'role_assignment_scopes', 'role_assignments', 'org_teams',
            'org_closure', 'org_nodes', 'org_level_types',
            'members', 'users',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    // ───── Helpers ─────

    private function addMember(string $email, string $status, int $nodeId, ?string $endDate = null, ?string $startDate = null): void
    {
        $userId = $this->db->insert('users', ['email' => $email]);
        $this->db->insert('members', [
            'user_id' => $userId,
            'first_name' => 'F_' . $email,
            'surname' => 'S',
            'status' => $status,
        ]);
        $assignmentId = $this->db->insert('role_assignments', [
            'user_id' => $userId,
            'role_id' => 1,
            'start_date' => $startDate ?? '2020-01-01',
            'end_date' => $endDate,
        ]);
        $this->db->insert('role_assignment_scopes', [
            'assignment_id' => $assignmentId,
            'node_id' => $nodeId,
        ]);
    }

    private function countByNodeId(array $tree): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $n) {
                $out[(int) $n['id']] = [
                    'direct' => (int) ($n['member_count_direct'] ?? -1),
                    'total'  => (int) ($n['member_count_total']  ?? -1),
                ];
                if (!empty($n['children'])) {
                    $walk($n['children']);
                }
            }
        };
        $walk($tree);
        return $out;
    }

    // ───── Tests ─────

    public function testEmptyTreeHasZeroCounts(): void
    {
        $tree = $this->service->getTree();
        $counts = $this->countByNodeId($tree);

        $this->assertSame(0, $counts[$this->nodes['group']]['direct']);
        $this->assertSame(0, $counts[$this->nodes['group']]['total']);
        $this->assertSame(0, $counts[$this->nodes['cubs']]['total']);
    }

    public function testDirectAssignmentIsCountedAtNode(): void
    {
        $this->addMember('a@x.test', 'active', $this->nodes['cubs']);
        $this->addMember('b@x.test', 'active', $this->nodes['cubs']);

        $counts = $this->countByNodeId($this->service->getTree());

        $this->assertSame(2, $counts[$this->nodes['cubs']]['direct']);
        $this->assertSame(2, $counts[$this->nodes['cubs']]['total']);
    }

    public function testCountRollsUpToAncestorsViaClosure(): void
    {
        $this->addMember('a@x.test', 'active', $this->nodes['cubs']);
        $this->addMember('b@x.test', 'active', $this->nodes['scouts']);

        $counts = $this->countByNodeId($this->service->getTree());

        // Group has no direct members, but 2 via descendants
        $this->assertSame(0, $counts[$this->nodes['group']]['direct']);
        $this->assertSame(2, $counts[$this->nodes['group']]['total']);
        // Sibling branch untouched
        $this->assertSame(0, $counts[$this->nodes['other_group']]['total']);
    }

    public function testInactiveMembersExcluded(): void
    {
        $this->addMember('active@x.test', 'active',    $this->nodes['cubs']);
        $this->addMember('left@x.test',   'left',      $this->nodes['cubs']);
        $this->addMember('pending@x.test', 'pending',  $this->nodes['cubs']);

        $counts = $this->countByNodeId($this->service->getTree());
        $this->assertSame(1, $counts[$this->nodes['cubs']]['direct']);
    }

    public function testExpiredAssignmentsExcluded(): void
    {
        // Ended yesterday — should not count
        $this->addMember('expired@x.test', 'active', $this->nodes['cubs'], date('Y-m-d', strtotime('-1 day')));
        // Ends tomorrow — still active
        $this->addMember('current@x.test', 'active', $this->nodes['cubs'], date('Y-m-d', strtotime('+1 day')));
        // Starts tomorrow — not yet active
        $this->addMember('future@x.test',  'active', $this->nodes['cubs'], null, date('Y-m-d', strtotime('+1 day')));

        $counts = $this->countByNodeId($this->service->getTree());
        $this->assertSame(1, $counts[$this->nodes['cubs']]['direct']);
    }

    public function testMemberCountedOnceEvenWithMultipleScopes(): void
    {
        // One user with a single assignment scoped to two sibling nodes must
        // not be double-counted at the shared parent.
        $userId = $this->db->insert('users', ['email' => 'dual@x.test']);
        $this->db->insert('members', ['user_id' => $userId, 'first_name' => 'D', 'surname' => 'D', 'status' => 'active']);
        $assignmentId = $this->db->insert('role_assignments', [
            'user_id' => $userId, 'role_id' => 1, 'start_date' => '2020-01-01',
        ]);
        $this->db->insert('role_assignment_scopes', ['assignment_id' => $assignmentId, 'node_id' => $this->nodes['cubs']]);
        $this->db->insert('role_assignment_scopes', ['assignment_id' => $assignmentId, 'node_id' => $this->nodes['scouts']]);

        $counts = $this->countByNodeId($this->service->getTree());
        $this->assertSame(1, $counts[$this->nodes['group']]['total'], 'Parent rollup must de-duplicate via DISTINCT');
    }
}
