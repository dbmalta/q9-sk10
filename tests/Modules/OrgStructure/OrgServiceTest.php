<?php

declare(strict_types=1);

namespace Tests\Modules\OrgStructure;

use App\Core\Database;
use App\Modules\OrgStructure\Services\OrgService;
use PHPUnit\Framework\TestCase;

/**
 * OrgService tests — require a running MySQL test database.
 *
 * Tests cover closure table maintenance (insert, ancestors, descendants),
 * tree retrieval, node move, delete restrictions, and team CRUD.
 */
class OrgServiceTest extends TestCase
{
    private ?Database $db = null;
    private ?OrgService $service = null;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Clean up
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `member_attachments`");
        $this->db->query("DROP TABLE IF EXISTS `member_timeline`");
        $this->db->query("DROP TABLE IF EXISTS `medical_access_log`");
        $this->db->query("DROP TABLE IF EXISTS `member_pending_changes`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `org_teams`");
        $this->db->query("DROP TABLE IF EXISTS `org_closure`");
        $this->db->query("DROP TABLE IF EXISTS `org_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `org_level_types`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Create tables
        $this->db->query("
            CREATE TABLE `org_level_types` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `depth` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_leaf` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `org_nodes` (
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
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_test_org_nodes_parent` FOREIGN KEY (`parent_id`) REFERENCES `org_nodes`(`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_test_org_nodes_lt` FOREIGN KEY (`level_type_id`) REFERENCES `org_level_types`(`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `org_closure` (
                `ancestor_id` INT UNSIGNED NOT NULL,
                `descendant_id` INT UNSIGNED NOT NULL,
                `depth` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`ancestor_id`, `descendant_id`),
                CONSTRAINT `fk_test_closure_anc` FOREIGN KEY (`ancestor_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_test_closure_desc` FOREIGN KEY (`descendant_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `org_teams` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `node_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(200) NOT NULL,
                `description` TEXT NULL,
                `is_permanent` TINYINT(1) NOT NULL DEFAULT 1,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_test_teams_node` FOREIGN KEY (`node_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed level types
        $this->db->insert('org_level_types', ['name' => 'National', 'depth' => 0, 'sort_order' => 0]);
        $this->db->insert('org_level_types', ['name' => 'Region', 'depth' => 1, 'sort_order' => 1]);
        $this->db->insert('org_level_types', ['name' => 'Group', 'depth' => 2, 'sort_order' => 2]);
        $this->db->insert('org_level_types', ['name' => 'Section', 'depth' => 3, 'is_leaf' => 1, 'sort_order' => 3]);

        $this->service = new OrgService($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            $this->db->query("DROP TABLE IF EXISTS `org_teams`");
            $this->db->query("DROP TABLE IF EXISTS `org_closure`");
            $this->db->query("DROP TABLE IF EXISTS `org_nodes`");
            $this->db->query("DROP TABLE IF EXISTS `org_level_types`");
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    /**
     * Create a simple tree: National → Region → Group → Section
     * @return array<string, int> Node IDs keyed by name
     */
    private function createSampleTree(): array
    {
        $national = $this->service->createNode(['name' => 'Scouts of Northland', 'level_type_id' => 1]);
        $region = $this->service->createNode(['name' => 'Northern Region', 'parent_id' => $national, 'level_type_id' => 2]);
        $group = $this->service->createNode(['name' => '1st Northtown', 'parent_id' => $region, 'level_type_id' => 3]);
        $section = $this->service->createNode(['name' => 'Cubs', 'parent_id' => $group, 'level_type_id' => 4, 'age_group_min' => 8, 'age_group_max' => 10]);

        return ['national' => $national, 'region' => $region, 'group' => $group, 'section' => $section];
    }

    // ──── Node creation + closure table ────

    public function testCreateRootNode(): void
    {
        $id = $this->service->createNode(['name' => 'Root', 'level_type_id' => 1]);
        $this->assertGreaterThan(0, $id);

        // Check closure: self-reference only
        $closure = $this->db->fetchAll("SELECT * FROM org_closure WHERE ancestor_id = :id", ['id' => $id]);
        $this->assertCount(1, $closure);
        $this->assertSame($id, (int) $closure[0]['descendant_id']);
        $this->assertSame(0, (int) $closure[0]['depth']);
    }

    public function testCreateChildNode(): void
    {
        $parentId = $this->service->createNode(['name' => 'Parent', 'level_type_id' => 1]);
        $childId = $this->service->createNode(['name' => 'Child', 'parent_id' => $parentId, 'level_type_id' => 2]);

        // Check closure for child: self + link to parent
        $closure = $this->db->fetchAll(
            "SELECT * FROM org_closure WHERE descendant_id = :id ORDER BY depth",
            ['id' => $childId]
        );
        $this->assertCount(2, $closure);
        // depth 0 = self
        $this->assertSame($childId, (int) $closure[0]['ancestor_id']);
        $this->assertSame(0, (int) $closure[0]['depth']);
        // depth 1 = parent
        $this->assertSame($parentId, (int) $closure[1]['ancestor_id']);
        $this->assertSame(1, (int) $closure[1]['depth']);
    }

    public function testClosureTableMaintainedForDeepTree(): void
    {
        $ids = $this->createSampleTree();

        // Section should have 4 closure entries (self + group + region + national)
        $closure = $this->db->fetchAll(
            "SELECT * FROM org_closure WHERE descendant_id = :id ORDER BY depth",
            ['id' => $ids['section']]
        );
        $this->assertCount(4, $closure);

        $depths = array_column($closure, 'depth');
        sort($depths);
        $this->assertSame([0, 1, 2, 3], array_map('intval', $depths));
    }

    // ──── Ancestor/descendant queries ────

    public function testGetAncestorsReturnsPathFromRoot(): void
    {
        $ids = $this->createSampleTree();
        $ancestors = $this->service->getAncestors($ids['section']);

        // Should return: National → Region → Group → Section (root first)
        $this->assertCount(4, $ancestors);
        $this->assertSame('Scouts of Northland', $ancestors[0]['name']);
        $this->assertSame('Cubs', $ancestors[3]['name']);
    }

    public function testGetDescendantsIncludesSelfAndAll(): void
    {
        $ids = $this->createSampleTree();
        $descendants = $this->service->getDescendants($ids['national']);

        // National + Region + Group + Section = 4
        $this->assertCount(4, $descendants);
    }

    public function testGetDescendantIds(): void
    {
        $ids = $this->createSampleTree();
        $descIds = $this->service->getDescendantIds($ids['region']);

        // Region + Group + Section = 3
        $this->assertCount(3, $descIds);
        $this->assertContains($ids['region'], $descIds);
        $this->assertContains($ids['group'], $descIds);
        $this->assertContains($ids['section'], $descIds);
    }

    public function testGetChildren(): void
    {
        $ids = $this->createSampleTree();
        $children = $this->service->getChildren($ids['national']);

        $this->assertCount(1, $children);
        $this->assertSame('Northern Region', $children[0]['name']);
    }

    // ──── Tree building ────

    public function testGetTreeReturnsNestedStructure(): void
    {
        $this->createSampleTree();
        $tree = $this->service->getTree();

        $this->assertCount(1, $tree); // one root
        $this->assertSame('Scouts of Northland', $tree[0]['name']);
        $this->assertCount(1, $tree[0]['children']); // one region
        $this->assertSame('Northern Region', $tree[0]['children'][0]['name']);
    }

    // ──── Node update ────

    public function testUpdateNodeChangesName(): void
    {
        $id = $this->service->createNode(['name' => 'Old Name', 'level_type_id' => 1]);
        $this->service->updateNode($id, ['name' => 'New Name']);

        $node = $this->service->getNode($id);
        $this->assertSame('New Name', $node['name']);
    }

    // ──── Node deletion ────

    public function testDeleteLeafNode(): void
    {
        $ids = $this->createSampleTree();
        $this->service->deleteNode($ids['section']);

        $node = $this->service->getNode($ids['section']);
        $this->assertNull($node);

        // Closure entries should be gone
        $closure = $this->db->fetchAll(
            "SELECT * FROM org_closure WHERE descendant_id = :id",
            ['id' => $ids['section']]
        );
        $this->assertEmpty($closure);
    }

    public function testDeleteNodeWithChildrenThrows(): void
    {
        $ids = $this->createSampleTree();

        $this->expectException(\RuntimeException::class);
        $this->service->deleteNode($ids['group']); // has children (section)
    }

    public function testDeleteNodeWithTeamsThrows(): void
    {
        $id = $this->service->createNode(['name' => 'With Team', 'level_type_id' => 1]);
        $this->service->createTeam(['node_id' => $id, 'name' => 'Board']);

        $this->expectException(\RuntimeException::class);
        $this->service->deleteNode($id);
    }

    // ──── Team CRUD ────

    public function testCreateTeam(): void
    {
        $nodeId = $this->service->createNode(['name' => 'HQ', 'level_type_id' => 1]);
        $teamId = $this->service->createTeam([
            'node_id' => $nodeId,
            'name' => 'Finance Team',
            'is_permanent' => 1,
        ]);

        $this->assertGreaterThan(0, $teamId);
        $team = $this->service->getTeam($teamId);
        $this->assertSame('Finance Team', $team['name']);
    }

    public function testGetTeamsForNode(): void
    {
        $nodeId = $this->service->createNode(['name' => 'HQ', 'level_type_id' => 1]);
        $this->service->createTeam(['node_id' => $nodeId, 'name' => 'Board']);
        $this->service->createTeam(['node_id' => $nodeId, 'name' => 'Finance']);

        $teams = $this->service->getTeamsForNode($nodeId);
        $this->assertCount(2, $teams);
    }

    public function testDeleteTeam(): void
    {
        $nodeId = $this->service->createNode(['name' => 'HQ', 'level_type_id' => 1]);
        $teamId = $this->service->createTeam(['node_id' => $nodeId, 'name' => 'Temp']);
        $this->service->deleteTeam($teamId);

        $team = $this->service->getTeam($teamId);
        $this->assertNull($team);
    }

    // ──── Level types ────

    public function testGetLevelTypes(): void
    {
        $levels = $this->service->getLevelTypes();
        $this->assertCount(4, $levels); // seeded in setUp
    }

    public function testCreateLevelType(): void
    {
        $id = $this->service->createLevelType(['name' => 'District', 'depth' => 2, 'sort_order' => 2]);
        $this->assertGreaterThan(0, $id);
    }

    public function testDeleteLevelTypeInUseThrows(): void
    {
        $this->service->createNode(['name' => 'Root', 'level_type_id' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->service->deleteLevelType(1); // National is in use
    }

    public function testDeleteUnusedLevelType(): void
    {
        $id = $this->service->createLevelType(['name' => 'Unused', 'depth' => 5]);
        $this->service->deleteLevelType($id);

        $level = $this->service->getLevelType($id);
        $this->assertNull($level);
    }

    // ──── Closure integrity ────

    public function testClosureIntegrityAfterMultipleInserts(): void
    {
        // Create a tree with multiple branches
        $national = $this->service->createNode(['name' => 'National', 'level_type_id' => 1]);
        $region1 = $this->service->createNode(['name' => 'Region 1', 'parent_id' => $national, 'level_type_id' => 2]);
        $region2 = $this->service->createNode(['name' => 'Region 2', 'parent_id' => $national, 'level_type_id' => 2]);
        $group1 = $this->service->createNode(['name' => 'Group 1', 'parent_id' => $region1, 'level_type_id' => 3]);
        $group2 = $this->service->createNode(['name' => 'Group 2', 'parent_id' => $region2, 'level_type_id' => 3]);

        // National should have 5 descendants (including self)
        $this->assertCount(5, $this->service->getDescendantIds($national));

        // Region 1 should have 2 descendants (self + group1)
        $this->assertCount(2, $this->service->getDescendantIds($region1));

        // Region 2 should have 2 descendants (self + group2)
        $this->assertCount(2, $this->service->getDescendantIds($region2));

        // Group 1 should not be a descendant of Region 2
        $r2Descendants = $this->service->getDescendantIds($region2);
        $this->assertNotContains($group1, $r2Descendants);
    }
}
