<?php

declare(strict_types=1);

namespace App\Modules\Directory\Services;

use App\Core\Database;

/**
 * Directory and organogram service.
 *
 * Provides a public-facing directory of key role holders across the
 * organisation tree. Only role assignments where the role has
 * `is_directory_visible = 1` and the assignment is currently active
 * (start_date <= today, end_date is null or >= today) are included.
 *
 * This module does not own any tables — it queries org_nodes,
 * role_assignments, roles, members, and users.
 */
class DirectoryService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ──── Organogram ────

    /**
     * Build the full org tree with key role holders per node.
     *
     * Returns a nested array where each node contains:
     *   - id, name, level_type (from org_level_types)
     *   - key_roles: array of visible role holders
     *   - children: nested child nodes
     *
     * @return array Nested org tree with role holders
     */
    public function getOrganogram(): array
    {
        // Fetch all active nodes
        $nodes = $this->db->fetchAll(
            "SELECT n.id, n.name, n.parent_id, n.sort_order,
                    lt.name AS level_type
             FROM org_nodes n
             JOIN org_level_types lt ON lt.id = n.level_type_id
             WHERE n.is_active = 1
             ORDER BY n.sort_order ASC, n.name ASC"
        );

        // Fetch all directory-visible role holders in one query
        $roleHolders = $this->fetchDirectoryRoleHolders();

        // Index role holders by node ID for fast lookup
        $rolesByNode = [];
        foreach ($roleHolders as $holder) {
            $nodeId = (int) $holder['node_id'];
            $rolesByNode[$nodeId][] = [
                'member_name' => $holder['member_name'],
                'role_name' => $holder['role_name'],
                'email' => $holder['email'],
                'phone' => $holder['phone'],
            ];
        }

        // Build the nested tree
        return $this->buildOrganogramTree($nodes, $rolesByNode);
    }

    // ──── Contact directory ────

    /**
     * Get a searchable flat list of directory-visible role holders.
     *
     * Returns an array of contacts, each with member_name, role_name,
     * email, phone, and node_name. Results can be filtered by node
     * and/or a free-text search string (matches member name, role name,
     * or node name).
     *
     * @param int|null    $nodeId Filter by org node, or null for all
     * @param string|null $search Free-text search string
     * @return array Flat list of directory contacts
     */
    public function getContactDirectory(?int $nodeId = null, ?string $search = null, array $scopeNodeIds = []): array
    {
        $conditions = [
            "r.is_directory_visible = 1",
            "ra.start_date <= CURDATE()",
            "(ra.end_date IS NULL OR ra.end_date >= CURDATE())",
            "ra.context_type = 'node'",
        ];
        $params = [];

        if ($nodeId !== null) {
            $conditions[] = "ra.context_id = :node_id";
            $params['node_id'] = $nodeId;
        }

        if (!empty($scopeNodeIds)) {
            $placeholders = [];
            foreach ($scopeNodeIds as $i => $id) {
                $key = "scope_$i";
                $placeholders[] = ":$key";
                $params[$key] = (int) $id;
            }
            $conditions[] = 'ra.context_id IN (' . implode(',', $placeholders) . ')';
        }

        if ($search !== null && trim($search) !== '') {
            $conditions[] = "(
                m.first_name LIKE :search
                OR m.surname LIKE :search
                OR CONCAT(m.first_name, ' ', m.surname) LIKE :search
                OR r.name LIKE :search
                OR n.name LIKE :search
            )";
            $params['search'] = '%' . trim($search) . '%';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        return $this->db->fetchAll(
            "SELECT CONCAT(m.first_name, ' ', m.surname) AS member_name,
                    r.name AS role_name,
                    m.email,
                    m.phone,
                    n.name AS node_name
             FROM role_assignments ra
             JOIN roles r ON r.id = ra.role_id
             JOIN users u ON u.id = ra.user_id
             JOIN members m ON m.user_id = u.id
             JOIN org_nodes n ON n.id = ra.context_id
             $where
             ORDER BY n.name ASC, r.name ASC, m.surname ASC, m.first_name ASC",
            $params
        );
    }

    // ──── Per-node roles ────

    /**
     * Get directory-visible role holders for a specific node.
     *
     * @param int $nodeId Org node ID
     * @return array List of role holders with member_name, role_name, email, phone
     */
    public function getKeyRolesForNode(int $nodeId): array
    {
        return $this->db->fetchAll(
            "SELECT CONCAT(m.first_name, ' ', m.surname) AS member_name,
                    r.name AS role_name,
                    m.email,
                    m.phone
             FROM role_assignments ra
             JOIN roles r ON r.id = ra.role_id
             JOIN users u ON u.id = ra.user_id
             JOIN members m ON m.user_id = u.id
             WHERE ra.context_type = 'node'
               AND ra.context_id = :node_id
               AND r.is_directory_visible = 1
               AND ra.start_date <= CURDATE()
               AND (ra.end_date IS NULL OR ra.end_date >= CURDATE())
             ORDER BY r.name ASC, m.surname ASC, m.first_name ASC",
            ['node_id' => $nodeId]
        );
    }

    // ──── Private helpers ────

    /**
     * Fetch all currently active, directory-visible role holders across all nodes.
     *
     * @return array Raw rows with node_id, member_name, role_name, email, phone
     */
    private function fetchDirectoryRoleHolders(): array
    {
        return $this->db->fetchAll(
            "SELECT ra.context_id AS node_id,
                    CONCAT(m.first_name, ' ', m.surname) AS member_name,
                    r.name AS role_name,
                    m.email,
                    m.phone
             FROM role_assignments ra
             JOIN roles r ON r.id = ra.role_id
             JOIN users u ON u.id = ra.user_id
             JOIN members m ON m.user_id = u.id
             WHERE ra.context_type = 'node'
               AND r.is_directory_visible = 1
               AND ra.start_date <= CURDATE()
               AND (ra.end_date IS NULL OR ra.end_date >= CURDATE())
             ORDER BY r.name ASC, m.surname ASC, m.first_name ASC"
        );
    }

    /**
     * Build a nested organogram tree from a flat list of nodes,
     * attaching key role holders to each node.
     *
     * @param array $nodes       Flat list of org nodes
     * @param array $rolesByNode Role holders indexed by node ID
     * @param int|null $parentId Parent ID to filter by (null for root nodes)
     * @return array Nested tree
     */
    private function buildOrganogramTree(array $nodes, array $rolesByNode, ?int $parentId = null): array
    {
        $tree = [];

        foreach ($nodes as $node) {
            $nodeParent = $node['parent_id'] !== null ? (int) $node['parent_id'] : null;

            if ($nodeParent === $parentId) {
                $nodeId = (int) $node['id'];
                $tree[] = [
                    'id' => $nodeId,
                    'name' => $node['name'],
                    'level_type' => $node['level_type'],
                    'key_roles' => $rolesByNode[$nodeId] ?? [],
                    'children' => $this->buildOrganogramTree($nodes, $rolesByNode, $nodeId),
                ];
            }
        }

        return $tree;
    }
}
