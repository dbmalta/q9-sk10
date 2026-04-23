<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use App\Core\Database;
use App\Core\ViewContext;
use App\Modules\Members\Services\MemberService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for MemberService::listScoped — Phase 4's scope filter.
 *
 * Covers the real subtree expansion via org_closure: a user whose active
 * scope is a parent node should see members assigned to any descendant,
 * and vice versa the controller-level empty-state logic depends on these
 * returning 0 rows when the user's scope is narrow and data lives elsewhere.
 */
class MemberServiceScopingTest extends TestCase
{
    private Database $db;
    private MemberService $svc;

    /** @var array<string, int> */
    private array $nodeIds = [];

    /** @var array<string, int> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach ([
            'member_pending_changes', 'member_nodes', 'members',
            'org_closure', 'org_nodes',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL,
            `parent_id` INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `org_closure` (
            `ancestor_id` INT UNSIGNED NOT NULL,
            `descendant_id` INT UNSIGNED NOT NULL,
            `depth` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`ancestor_id`, `descendant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `members` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `membership_number` VARCHAR(20) NOT NULL,
            `first_name` VARCHAR(100) NOT NULL,
            `surname` VARCHAR(100) NOT NULL,
            `email` VARCHAR(255) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `user_id` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FULLTEXT KEY `ft` (`first_name`, `surname`, `email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `member_nodes` (
            `member_id` INT UNSIGNED NOT NULL,
            `node_id` INT UNSIGNED NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`member_id`, `node_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Tree:  District A  ──►  Group 1  ──►  Patrol Blue
        //                         Group 2
        //        District B
        $this->nodeIds['districtA'] = $this->db->insert('org_nodes', ['name' => 'District A']);
        $this->nodeIds['group1']    = $this->db->insert('org_nodes', ['name' => 'Group 1',     'parent_id' => $this->nodeIds['districtA']]);
        $this->nodeIds['group2']    = $this->db->insert('org_nodes', ['name' => 'Group 2',     'parent_id' => $this->nodeIds['districtA']]);
        $this->nodeIds['blue']      = $this->db->insert('org_nodes', ['name' => 'Patrol Blue', 'parent_id' => $this->nodeIds['group1']]);
        $this->nodeIds['districtB'] = $this->db->insert('org_nodes', ['name' => 'District B']);

        // Closure rows: every node is its own ancestor (depth 0) plus ancestors upwards.
        foreach ($this->nodeIds as $id) {
            $this->db->insert('org_closure', ['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);
        }
        $edges = [
            ['districtA', 'group1', 1],
            ['districtA', 'group2', 1],
            ['districtA', 'blue',   2],
            ['group1',    'blue',   1],
        ];
        foreach ($edges as [$a, $d, $depth]) {
            $this->db->insert('org_closure', [
                'ancestor_id' => $this->nodeIds[$a],
                'descendant_id' => $this->nodeIds[$d],
                'depth' => $depth,
            ]);
        }

        // Members: one at each node.
        foreach (['districtA', 'group1', 'group2', 'blue', 'districtB'] as $key) {
            $id = $this->db->insert('members', [
                'membership_number' => 'M-' . $key,
                'first_name' => ucfirst($key),
                'surname' => 'Smith',
            ]);
            $this->db->insert('member_nodes', [
                'member_id' => $id,
                'node_id' => $this->nodeIds[$key],
                'is_primary' => 1,
            ]);
            $this->memberIds[$key] = $id;
        }

        $this->svc = new MemberService($this->db);
    }

    /**
     * Scope = District A → returns the District A member AND all descendants
     * (Group 1, Group 2, Patrol Blue) via org_closure expansion. District B's
     * member must NOT appear.
     */
    public function testListScopedExpandsSubtreeFromActiveScope(): void
    {
        $ctx = $this->makeCtx(
            activeScopeNodeId: $this->nodeIds['districtA'],
            availableScopes: [$this->scope('districtA')],
        );

        $names = array_column($this->svc->listScoped($ctx)['items'], 'first_name');
        sort($names);
        $this->assertSame(['Blue', 'DistrictA', 'Group1', 'Group2'], $names);
    }

    /**
     * Leaf scope (Patrol Blue) → only the Patrol Blue member. Ancestors of
     * Blue (Group 1, District A) should NOT appear in this first-cut
     * implementation — ancestor cascade is a deferred Phase-4.b decision.
     */
    public function testListScopedLeafReturnsOnlyLeafMember(): void
    {
        $ctx = $this->makeCtx(
            activeScopeNodeId: $this->nodeIds['blue'],
            availableScopes: [$this->scope('blue')],
        );

        $names = array_column($this->svc->listScoped($ctx)['items'], 'first_name');
        $this->assertSame(['Blue'], $names);
    }

    /**
     * "All nodes" for a user with two disjoint scopes returns the union of
     * both subtrees — but nothing from outside them.
     */
    public function testListScopedAllNodesReturnsUnionOfUserScopes(): void
    {
        $ctx = $this->makeCtx(
            activeScopeNodeId: null,
            availableScopes: [$this->scope('group1'), $this->scope('districtB')],
        );

        $names = array_column($this->svc->listScoped($ctx)['items'], 'first_name');
        sort($names);
        // Group 1 subtree = Group1 + Blue. District B subtree = Districtb only.
        $this->assertSame(['Blue', 'DistrictB', 'Group1'], $names);
    }

    public function testListScopedReturnsEmptyWhenUserHasNoScopes(): void
    {
        $ctx = $this->makeCtx(activeScopeNodeId: null, availableScopes: []);
        $result = $this->svc->listScoped($ctx);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    public function testExpandNodeSubtreeDedupesOverlappingRoots(): void
    {
        // District A and Group 1 overlap: Group 1 and Patrol Blue appear from both.
        $expanded = $this->svc->expandNodeSubtree([
            $this->nodeIds['districtA'],
            $this->nodeIds['group1'],
        ]);

        // Four distinct ids: districtA, group1, group2, blue.
        $this->assertCount(4, array_unique($expanded));
    }

    public function testIsMemberInScopeRespectsSubtreeExpansion(): void
    {
        $ctx = $this->makeCtx(
            activeScopeNodeId: $this->nodeIds['districtA'],
            availableScopes: [$this->scope('districtA')],
        );

        $this->assertTrue($this->svc->isMemberInScope($this->memberIds['blue'], $ctx));
        $this->assertFalse($this->svc->isMemberInScope($this->memberIds['districtB'], $ctx));
    }

    public function testIsMemberInScopeFalseForEmptyScope(): void
    {
        $ctx = $this->makeCtx(activeScopeNodeId: null, availableScopes: []);
        $this->assertFalse($this->svc->isMemberInScope($this->memberIds['blue'], $ctx));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @param array<int, array{node_id:int, path:string, leaf:string}> $availableScopes */
    private function makeCtx(?int $activeScopeNodeId, array $availableScopes): ViewContext
    {
        return new ViewContext(
            mode: ViewContext::MODE_ADMIN,
            activeScopeNodeId: $activeScopeNodeId,
            availableScopes: $availableScopes,
            canSwitchToAdmin: true,
            canSwitchToMember: false,
        );
    }

    /** @return array{node_id:int, path:string, leaf:string} */
    private function scope(string $key): array
    {
        return ['node_id' => $this->nodeIds[$key], 'path' => $key, 'leaf' => $key];
    }
}
