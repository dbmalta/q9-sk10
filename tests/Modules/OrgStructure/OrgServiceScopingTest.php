<?php

declare(strict_types=1);

namespace Tests\Modules\OrgStructure;

use App\Core\Database;
use App\Modules\OrgStructure\Services\OrgService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for OrgService::getTree's scope-filter hook.
 *
 * Seeds two disjoint branches (District A with two groups, District B
 * with one group), assigns members at various depths via
 * role_assignments + role_assignment_scopes (the counting source used by
 * getMemberCountsByNode), and asserts that passing an allowed-node-ids
 * subset restricts both the nodes returned and the member-count rollups
 * to that subtree. Mirrors OrgTreeMemberCountsTest's fixture style.
 */
class OrgServiceScopingTest extends TestCase
{
    private Database $db;
    private OrgService $svc;

    /** @var array<string, int> */
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
            'role_assignment_scopes', 'role_assignments',
            'org_teams', 'org_closure', 'org_nodes', 'org_level_types',
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
            `start_date` DATE NOT NULL,
            `end_date` DATE NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `role_assignment_scopes` (
            `assignment_id` INT UNSIGNED NOT NULL,
            `node_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`assignment_id`, `node_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->insert('org_level_types', ['id' => 1, 'name' => 'District']);
        $this->db->insert('org_level_types', ['id' => 2, 'name' => 'Group']);

        $this->svc = new OrgService($this->db);

        // District A → Group A1, Group A2
        // District B → Group B1
        $this->nodes['districtA'] = $this->svc->createNode(['name' => 'District A', 'level_type_id' => 1]);
        $this->nodes['groupA1']   = $this->svc->createNode(['name' => 'Group A1', 'parent_id' => $this->nodes['districtA'], 'level_type_id' => 2]);
        $this->nodes['groupA2']   = $this->svc->createNode(['name' => 'Group A2', 'parent_id' => $this->nodes['districtA'], 'level_type_id' => 2]);
        $this->nodes['districtB'] = $this->svc->createNode(['name' => 'District B', 'level_type_id' => 1]);
        $this->nodes['groupB1']   = $this->svc->createNode(['name' => 'Group B1', 'parent_id' => $this->nodes['districtB'], 'level_type_id' => 2]);

        // Active members scoped via role assignments:
        //   2 in Group A1, 1 in Group A2, 3 in Group B1, 1 in District A.
        $this->seedMembers($this->nodes['groupA1'], 2);
        $this->seedMembers($this->nodes['groupA2'], 1);
        $this->seedMembers($this->nodes['groupB1'], 3);
        $this->seedMembers($this->nodes['districtA'], 1);
    }

    protected function tearDown(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach ([
            'role_assignment_scopes', 'role_assignments',
            'org_teams', 'org_closure', 'org_nodes', 'org_level_types',
            'members', 'users',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    private function seedMembers(int $nodeId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $uid = $this->db->insert('users', [
                'email' => 'u' . $nodeId . '-' . $i . '-' . uniqid('', true) . '@example.test',
            ]);
            $this->db->insert('members', [
                'user_id'    => $uid,
                'first_name' => 'M',
                'surname'    => (string) $nodeId . '-' . $i,
                'status'     => 'active',
            ]);
            $assignmentId = $this->db->insert('role_assignments', [
                'user_id'    => $uid,
                'start_date' => '2020-01-01',
            ]);
            $this->db->insert('role_assignment_scopes', [
                'assignment_id' => $assignmentId,
                'node_id'       => $nodeId,
            ]);
        }
    }

    public function testGetTreeWithoutFilterReturnsFullTreeAndRollup(): void
    {
        $tree = $this->svc->getTree();
        $this->assertCount(2, $tree);
        $names = array_column($tree, 'name');
        sort($names);
        $this->assertSame(['District A', 'District B'], $names);

        $byName = $this->index($tree);
        // District A rollup = self (1) + Group A1 (2) + Group A2 (1) = 4
        $this->assertSame(4, (int) $byName['District A']['member_count_total']);
        $this->assertSame(1, (int) $byName['District A']['member_count_direct']);
        $this->assertSame(3, (int) $byName['District B']['member_count_total']);
    }

    public function testGetTreeRestrictedToDistrictAShowsSubtreeOnly(): void
    {
        $allowed = $this->svc->expandAllowedSubtree([$this->nodes['districtA']]);
        $tree = $this->svc->getTree(true, $allowed);

        $this->assertCount(1, $tree);
        $this->assertSame('District A', $tree[0]['name']);
        $childNames = array_column($tree[0]['children'], 'name');
        sort($childNames);
        $this->assertSame(['Group A1', 'Group A2'], $childNames);

        // Rollup on District A still sees the 4 members in its subtree.
        $this->assertSame(4, (int) $tree[0]['member_count_total']);
    }

    public function testGetTreeRollupExcludesMembersOutsideAllowedSet(): void
    {
        // Scope = Group A1 only. District A's direct member and Group A2's
        // member must NOT contribute, even though they share ancestors.
        $allowed = [$this->nodes['groupA1']];
        $tree = $this->svc->getTree(true, $allowed);

        $this->assertCount(1, $tree);
        $this->assertSame('Group A1', $tree[0]['name']);
        $this->assertSame(2, (int) $tree[0]['member_count_total']);
        $this->assertSame(2, (int) $tree[0]['member_count_direct']);
    }

    public function testGetTreeEmptyAllowedReturnsEmpty(): void
    {
        $this->assertSame([], $this->svc->getTree(true, []));
    }

    public function testExpandAllowedSubtreeIncludesDescendants(): void
    {
        $ids = $this->svc->expandAllowedSubtree([$this->nodes['districtA']]);
        sort($ids);
        $expected = [$this->nodes['districtA'], $this->nodes['groupA1'], $this->nodes['groupA2']];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /** @param array<int, array<string, mixed>> $tree */
    private function index(array $tree): array
    {
        $out = [];
        foreach ($tree as $n) {
            $out[(string) $n['name']] = $n;
        }
        return $out;
    }
}
