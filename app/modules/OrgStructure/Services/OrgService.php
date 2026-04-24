<?php

declare(strict_types=1);

namespace App\Modules\OrgStructure\Services;

use App\Core\Database;

/**
 * Organisation structure service.
 *
 * Manages the hierarchy of org nodes, maintains the closure table
 * for efficient ancestor/descendant queries, and handles team CRUD.
 * The closure table stores every ancestor→descendant path with its depth,
 * enabling single-query subtree retrieval and tree rendering.
 */
class OrgService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ──── Node CRUD ────

    /**
     * Create a new org node and maintain the closure table.
     *
     * @param array $data Node data: name, parent_id, level_type_id, etc.
     * @return int The new node ID
     */
    public function createNode(array $data): int
    {
        $nodeId = $this->db->insert('org_nodes', [
            'parent_id' => $data['parent_id'] ?? null,
            'level_type_id' => $data['level_type_id'],
            'name' => $data['name'],
            'short_name' => $data['short_name'] ?? null,
            'description' => $data['description'] ?? null,
            'age_group_min' => $data['age_group_min'] ?? null,
            'age_group_max' => $data['age_group_max'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1,
        ]);

        // Build closure entries: copy parent's ancestors + self-reference
        if (!empty($data['parent_id'])) {
            $this->db->query(
                "INSERT INTO org_closure (ancestor_id, descendant_id, depth)
                 SELECT ancestor_id, :new_id, depth + 1
                 FROM org_closure
                 WHERE descendant_id = :parent_id
                 UNION ALL
                 SELECT :new_id2, :new_id3, 0",
                [
                    'new_id' => $nodeId,
                    'parent_id' => $data['parent_id'],
                    'new_id2' => $nodeId,
                    'new_id3' => $nodeId,
                ]
            );
        } else {
            // Root node — self-reference only
            $this->db->insert('org_closure', [
                'ancestor_id' => $nodeId,
                'descendant_id' => $nodeId,
                'depth' => 0,
            ]);
        }

        return $nodeId;
    }

    /**
     * Update an org node's properties (does not move it in the tree).
     *
     * @param int $nodeId The node ID
     * @param array $data Fields to update
     */
    public function updateNode(int $nodeId, array $data): void
    {
        $allowed = ['name', 'short_name', 'description', 'age_group_min', 'age_group_max', 'sort_order', 'is_active'];
        $update = array_intersect_key($data, array_flip($allowed));

        if (!empty($update)) {
            $this->db->update('org_nodes', $update, ['id' => $nodeId]);
        }
    }

    /**
     * Move a node to a new parent, rebuilding the closure table.
     *
     * @param int $nodeId The node to move
     * @param int|null $newParentId The new parent (null for root)
     * @throws \RuntimeException if the move would create a cycle
     */
    public function moveNode(int $nodeId, ?int $newParentId): void
    {
        // Prevent moving a node under itself (cycle check)
        if ($newParentId !== null) {
            $descendants = $this->getDescendantIds($nodeId);
            if (in_array($newParentId, $descendants, true)) {
                throw new \RuntimeException('Cannot move a node under one of its own descendants');
            }
        }

        // Step 1: Remove all closure entries where the descendant is in the subtree
        // AND the ancestor is NOT in the subtree (external links only)
        $subtreeIds = $this->getDescendantIds($nodeId);
        if (!empty($subtreeIds)) {
            $placeholders = implode(',', array_fill(0, count($subtreeIds), '?'));
            $this->db->query(
                "DELETE FROM org_closure
                 WHERE descendant_id IN ($placeholders)
                   AND ancestor_id NOT IN ($placeholders)",
                array_merge($subtreeIds, $subtreeIds)
            );
        }

        // Step 2: Update the node's parent_id
        $this->db->update('org_nodes', ['parent_id' => $newParentId], ['id' => $nodeId]);

        // Step 3: Rebuild closure entries for the subtree under the new parent
        if ($newParentId !== null) {
            // Get all internal closure entries for the subtree
            $subtreeClosure = $this->db->fetchAll(
                "SELECT ancestor_id, descendant_id, depth FROM org_closure
                 WHERE ancestor_id = :node_id OR (ancestor_id IN (
                     SELECT descendant_id FROM org_closure WHERE ancestor_id = :node_id2 AND depth > 0
                 ))",
                ['node_id' => $nodeId, 'node_id2' => $nodeId]
            );

            // For each ancestor of the new parent (including new parent itself),
            // create closure entries to each node in the subtree
            $newParentAncestors = $this->db->fetchAll(
                "SELECT ancestor_id, depth FROM org_closure WHERE descendant_id = :parent_id",
                ['parent_id' => $newParentId]
            );

            foreach ($newParentAncestors as $ancestor) {
                foreach ($subtreeIds as $descId) {
                    // Get depth of descId relative to nodeId
                    $relativeDepth = $this->db->fetchColumn(
                        "SELECT depth FROM org_closure WHERE ancestor_id = :anc AND descendant_id = :desc",
                        ['anc' => $nodeId, 'desc' => $descId]
                    );

                    if ($relativeDepth !== null) {
                        $totalDepth = (int) $ancestor['depth'] + 1 + (int) $relativeDepth;
                        $this->db->query(
                            "INSERT IGNORE INTO org_closure (ancestor_id, descendant_id, depth) VALUES (:anc, :desc, :depth)",
                            ['anc' => $ancestor['ancestor_id'], 'desc' => $descId, 'depth' => $totalDepth]
                        );
                    }
                }
            }
        }
    }

    /**
     * Delete an org node (only if it has no children).
     *
     * @param int $nodeId The node ID
     * @throws \RuntimeException if the node has children
     */
    public function deleteNode(int $nodeId): void
    {
        $children = $this->getChildren($nodeId);
        if (!empty($children)) {
            throw new \RuntimeException('Cannot delete a node that has children. Remove or move children first.');
        }

        // Check for teams
        $teamCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM org_teams WHERE node_id = :id",
            ['id' => $nodeId]
        );

        if ($teamCount > 0) {
            throw new \RuntimeException('Cannot delete a node that has teams. Remove teams first.');
        }

        // Closure entries are deleted via CASCADE
        $this->db->delete('org_nodes', ['id' => $nodeId]);
    }

    // ──── Tree queries ────

    /**
     * Get the full org tree as a nested array.
     *
     * @param bool $activeOnly Only include active nodes
     * @return array Nested tree structure
     */
    public function getTree(bool $activeOnly = true): array
    {
        $sql = "SELECT n.*, lt.name AS level_type_name, lt.is_leaf,
                       (SELECT COUNT(*) FROM org_teams t WHERE t.node_id = n.id) AS team_count
                FROM org_nodes n
                JOIN org_level_types lt ON lt.id = n.level_type_id";

        if ($activeOnly) {
            $sql .= " WHERE n.is_active = 1";
        }

        $sql .= " ORDER BY n.sort_order, n.name";
        $nodes = $this->db->fetchAll($sql);

        $counts = $this->getMemberCountsByNode();
        foreach ($nodes as &$n) {
            $id = (int) $n['id'];
            $n['member_count_direct'] = $counts['direct'][$id] ?? 0;
            $n['member_count_total']  = $counts['total'][$id]  ?? 0;
        }
        unset($n);

        return $this->buildTree($nodes);
    }

    /**
     * Count active members per org node — both directly assigned and
     * rolled up via the closure table. A member is counted at a node if
     * their linked user has a currently-active role assignment scoped to
     * that node (direct) or any descendant of it (total).
     *
     * @return array{direct: array<int,int>, total: array<int,int>}
     */
    private function getMemberCountsByNode(): array
    {
        $direct = [];
        $rows = $this->db->fetchAll(
            "SELECT ras.node_id AS node_id, COUNT(DISTINCT m.id) AS cnt
             FROM role_assignment_scopes ras
             JOIN role_assignments ra ON ra.id = ras.assignment_id
              AND ra.start_date <= CURRENT_DATE
              AND (ra.end_date IS NULL OR ra.end_date >= CURRENT_DATE)
             JOIN members m ON m.user_id = ra.user_id AND m.status = 'active'
             GROUP BY ras.node_id"
        );
        foreach ($rows as $r) {
            $direct[(int) $r['node_id']] = (int) $r['cnt'];
        }

        $total = [];
        $rows = $this->db->fetchAll(
            "SELECT c.ancestor_id AS node_id, COUNT(DISTINCT m.id) AS cnt
             FROM org_closure c
             JOIN role_assignment_scopes ras ON ras.node_id = c.descendant_id
             JOIN role_assignments ra ON ra.id = ras.assignment_id
              AND ra.start_date <= CURRENT_DATE
              AND (ra.end_date IS NULL OR ra.end_date >= CURRENT_DATE)
             JOIN members m ON m.user_id = ra.user_id AND m.status = 'active'
             GROUP BY c.ancestor_id"
        );
        foreach ($rows as $r) {
            $total[(int) $r['node_id']] = (int) $r['cnt'];
        }

        return ['direct' => $direct, 'total' => $total];
    }

    /**
     * Get a single node by ID.
     */
    public function getNode(int $nodeId): ?array
    {
        return $this->db->fetchOne(
            "SELECT n.*, lt.name AS level_type_name, lt.is_leaf
             FROM org_nodes n
             JOIN org_level_types lt ON lt.id = n.level_type_id
             WHERE n.id = :id",
            ['id' => $nodeId]
        );
    }

    /**
     * Get all ancestors of a node (including the node itself), ordered root-first.
     *
     * @param int $nodeId The node ID
     * @return array Ancestor nodes from root to the given node
     */
    public function getAncestors(int $nodeId): array
    {
        return $this->db->fetchAll(
            "SELECT n.*, lt.name AS level_type_name, c.depth
             FROM org_closure c
             JOIN org_nodes n ON n.id = c.ancestor_id
             JOIN org_level_types lt ON lt.id = n.level_type_id
             WHERE c.descendant_id = :id
             ORDER BY c.depth DESC",
            ['id' => $nodeId]
        );
    }

    /**
     * Get all descendants of a node (including the node itself).
     *
     * @param int $nodeId The node ID
     * @return array Descendant nodes
     */
    public function getDescendants(int $nodeId): array
    {
        return $this->db->fetchAll(
            "SELECT n.*, lt.name AS level_type_name, c.depth
             FROM org_closure c
             JOIN org_nodes n ON n.id = c.descendant_id
             JOIN org_level_types lt ON lt.id = n.level_type_id
             WHERE c.ancestor_id = :id
             ORDER BY c.depth, n.sort_order, n.name",
            ['id' => $nodeId]
        );
    }

    /**
     * Get immediate children of a node.
     *
     * @param int $nodeId The parent node ID
     * @return array Child nodes
     */
    public function getChildren(int $nodeId): array
    {
        return $this->db->fetchAll(
            "SELECT n.*, lt.name AS level_type_name, lt.is_leaf
             FROM org_nodes n
             JOIN org_level_types lt ON lt.id = n.level_type_id
             WHERE n.parent_id = :parent_id
             ORDER BY n.sort_order, n.name",
            ['parent_id' => $nodeId]
        );
    }

    /**
     * Get descendant IDs only (including the node itself).
     *
     * @param int $nodeId The node ID
     * @return array<int> Descendant node IDs
     */
    public function getDescendantIds(int $nodeId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT descendant_id FROM org_closure WHERE ancestor_id = :id",
            ['id' => $nodeId]
        );

        return array_map(fn($r) => (int) $r['descendant_id'], $rows);
    }

    // ──── Team CRUD ────

    /**
     * Create a team attached to a node.
     */
    public function createTeam(array $data): int
    {
        return $this->db->insert('org_teams', [
            'node_id' => $data['node_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_permanent' => $data['is_permanent'] ?? 1,
            'is_active' => $data['is_active'] ?? 1,
        ]);
    }

    /**
     * Update a team.
     */
    public function updateTeam(int $teamId, array $data): void
    {
        $allowed = ['name', 'description', 'is_permanent', 'is_active'];
        $update = array_intersect_key($data, array_flip($allowed));

        if (!empty($update)) {
            $this->db->update('org_teams', $update, ['id' => $teamId]);
        }
    }

    /**
     * Delete a team.
     */
    public function deleteTeam(int $teamId): void
    {
        $this->db->delete('org_teams', ['id' => $teamId]);
    }

    /**
     * Get teams for a node.
     */
    public function getTeamsForNode(int $nodeId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM org_teams WHERE node_id = :node_id ORDER BY name",
            ['node_id' => $nodeId]
        );
    }

    /**
     * Get a team by ID.
     */
    public function getTeam(int $teamId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM org_teams WHERE id = :id",
            ['id' => $teamId]
        );
    }

    // ──── Level types ────

    /**
     * Get all level types ordered by depth/sort_order.
     */
    public function getLevelTypes(): array
    {
        return $this->db->fetchAll("SELECT * FROM org_level_types ORDER BY sort_order, depth");
    }

    /**
     * Get a level type by ID.
     */
    public function getLevelType(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM org_level_types WHERE id = :id", ['id' => $id]);
    }

    /**
     * Create a level type.
     */
    public function createLevelType(array $data): int
    {
        return $this->db->insert('org_level_types', [
            'name' => $data['name'],
            'depth' => $data['depth'] ?? 0,
            'is_leaf' => $data['is_leaf'] ?? 0,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /**
     * Update a level type.
     */
    public function updateLevelType(int $id, array $data): void
    {
        $allowed = ['name', 'depth', 'is_leaf', 'sort_order'];
        $update = array_intersect_key($data, array_flip($allowed));

        if (!empty($update)) {
            $this->db->update('org_level_types', $update, ['id' => $id]);
        }
    }

    /**
     * Delete a level type (only if no nodes use it).
     */
    public function deleteLevelType(int $id): void
    {
        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM org_nodes WHERE level_type_id = :id",
            ['id' => $id]
        );

        if ($count > 0) {
            throw new \RuntimeException('Cannot delete level type: it is used by existing nodes.');
        }

        $this->db->delete('org_level_types', ['id' => $id]);
    }

    // ──── Helpers ────

    /**
     * Build a nested tree from a flat list of nodes.
     */
    private function buildTree(array $nodes, ?int $parentId = null): array
    {
        $tree = [];
        foreach ($nodes as $node) {
            $nodeParent = $node['parent_id'] !== null ? (int) $node['parent_id'] : null;
            if ($nodeParent === $parentId) {
                $node['children'] = $this->buildTree($nodes, (int) $node['id']);
                $tree[] = $node;
            }
        }
        return $tree;
    }
}
