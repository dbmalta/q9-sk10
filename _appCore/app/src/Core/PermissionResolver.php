<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Permission resolver.
 *
 * Loads all active role assignments for a user, unions their permissions
 * and scope node IDs, and exposes efficient `can()` checks. Results are
 * cached in the session and invalidated by the Permissions module when
 * role assignments change.
 *
 * Permissions are fully explicit — holding a position never grants
 * anything implicitly. All access comes from a role assignment.
 */
class PermissionResolver
{
    private Database $db;
    private Session $session;

    /** @var array<string, bool> */
    private array $permissions = [];

    /** @var array<int> */
    private array $scopeNodeIds = [];

    private bool $isSuperAdmin = false;
    private array $activeAssignments = [];
    private bool $loaded = false;

    private const SESSION_KEY = '_permissions';

    public function __construct(Database $db, Session $session)
    {
        $this->db = $db;
        $this->session = $session;
    }

    public function loadForUser(int $userId): void
    {
        $cached = $this->session->get(self::SESSION_KEY);
        if ($cached !== null && ($cached['user_id'] ?? 0) === $userId) {
            $this->restoreFromCache($cached);
            return;
        }

        $this->loadFromDatabase($userId);
        $this->cacheToSession($userId);
    }

    public function can(string $permission): bool
    {
        if ($this->isSuperAdmin) {
            return true;
        }
        return $this->permissions[$permission] ?? false;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    /**
     * Unioned scope node IDs. Empty array means unrestricted (super-admin).
     *
     * @return array<int>
     */
    public function getScopeNodeIds(): array
    {
        if ($this->isSuperAdmin) {
            return [];
        }
        return $this->scopeNodeIds;
    }

    public function canAccessNode(int $nodeId): bool
    {
        if ($this->isSuperAdmin) {
            return true;
        }
        if (empty($this->scopeNodeIds)) {
            return false;
        }
        return in_array($nodeId, $this->scopeNodeIds, true);
    }

    public function getActiveAssignments(): array
    {
        return $this->activeAssignments;
    }

    public function invalidate(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->loaded = false;
        $this->permissions = [];
        $this->scopeNodeIds = [];
        $this->isSuperAdmin = false;
        $this->activeAssignments = [];
    }

    private function loadFromDatabase(int $userId): void
    {
        $user = $this->db->fetchOne(
            "SELECT is_super_admin FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if ($user !== null && (int) $user['is_super_admin'] === 1) {
            $this->isSuperAdmin = true;
        }

        $assignments = $this->db->fetchAll(
            "SELECT ra.*, r.name AS role_name, r.description AS role_description,
                    r.permissions AS role_permissions
             FROM role_assignments ra
             JOIN roles r ON r.id = ra.role_id
             WHERE ra.user_id = :user_id
               AND (ra.end_date IS NULL OR ra.end_date >= CURDATE())
             ORDER BY ra.start_date DESC",
            ['user_id' => $userId]
        );

        $this->activeAssignments = $assignments;

        foreach ($assignments as $assignment) {
            // Permissions JSON shape: {"module.action": true} or {"module": ["read","write"]}
            $rolePermissions = json_decode((string) $assignment['role_permissions'], true) ?? [];
            foreach ($rolePermissions as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $action) {
                        $this->permissions["$key.$action"] = true;
                    }
                } elseif ($value) {
                    $this->permissions[$key] = true;
                }
            }

            $scopes = $this->db->fetchAll(
                "SELECT node_id FROM role_assignment_scopes WHERE assignment_id = :id",
                ['id' => $assignment['id']]
            );
            foreach ($scopes as $scope) {
                $nodeId = (int) $scope['node_id'];
                if (!in_array($nodeId, $this->scopeNodeIds, true)) {
                    $this->scopeNodeIds[] = $nodeId;
                }
            }
        }

        $this->loaded = true;
    }

    private function cacheToSession(int $userId): void
    {
        $this->session->set(self::SESSION_KEY, [
            'user_id'            => $userId,
            'is_super_admin'     => $this->isSuperAdmin,
            'permissions'        => $this->permissions,
            'scope_node_ids'     => $this->scopeNodeIds,
            'active_assignments' => $this->activeAssignments,
        ]);
    }

    private function restoreFromCache(array $cached): void
    {
        $this->isSuperAdmin      = (bool) ($cached['is_super_admin'] ?? false);
        $this->permissions       = $cached['permissions'] ?? [];
        $this->scopeNodeIds      = $cached['scope_node_ids'] ?? [];
        $this->activeAssignments = $cached['active_assignments'] ?? [];
        $this->loaded = true;
    }
}
