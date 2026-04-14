<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Permission resolver.
 *
 * Loads all active role assignments for a user, unions their permissions
 * and scope nodes, and exposes efficient permission checks. Results are
 * cached in the session and invalidated on role changes.
 *
 * Permissions are fully explicit — holding a position or being a member
 * of a team grants nothing automatically. All access must be granted
 * through explicit role assignments.
 */
class PermissionResolver
{
    private Database $db;
    private Session $session;

    /** @var array<string, bool> Unioned permissions across all active assignments */
    private array $permissions = [];

    /** @var array<int> Unioned scope node IDs */
    private array $scopeNodeIds = [];

    /** @var bool Whether the user can publish events */
    private bool $canPublishEvents = false;

    /** @var bool Whether the user can access medical data */
    private bool $canAccessMedical = false;

    /** @var bool Whether the user can access financial data */
    private bool $canAccessFinancial = false;

    /** @var bool Whether the user is a super admin */
    private bool $isSuperAdmin = false;

    /** @var array Active assignments with role details */
    private array $activeAssignments = [];

    /** @var bool Whether data has been loaded */
    private bool $loaded = false;

    private const SESSION_KEY = '_permissions';

    public function __construct(Database $db, Session $session)
    {
        $this->db = $db;
        $this->session = $session;
    }

    /**
     * Load permissions for a user. Uses session cache if available.
     *
     * @param int $userId The user ID to load permissions for
     */
    public function loadForUser(int $userId): void
    {
        // Check session cache
        $cached = $this->session->get(self::SESSION_KEY);
        if ($cached !== null && ($cached['user_id'] ?? 0) === $userId) {
            $this->restoreFromCache($cached);
            return;
        }

        $this->loadFromDatabase($userId);
        $this->cacheToSession($userId);
    }

    /**
     * Check if the user has a specific permission.
     *
     * Super admins have all permissions. For other users, the permission
     * must be explicitly granted through one of their active role assignments.
     *
     * @param string $permission Permission key (e.g. 'members.read', 'events.write')
     * @return bool True if the user has the permission
     */
    public function can(string $permission): bool
    {
        if ($this->isSuperAdmin) {
            return true;
        }

        return $this->permissions[$permission] ?? false;
    }

    /**
     * Check if the user can publish events.
     */
    public function canPublishEvents(): bool
    {
        return $this->isSuperAdmin || $this->canPublishEvents;
    }

    /**
     * Check if the user can access medical data.
     * Requires the explicit can_access_medical flag on at least one role.
     */
    public function canAccessMedical(): bool
    {
        return $this->isSuperAdmin || $this->canAccessMedical;
    }

    /**
     * Check if the user can access financial data.
     * Requires the explicit can_access_financial flag on at least one role.
     */
    public function canAccessFinancial(): bool
    {
        return $this->isSuperAdmin || $this->canAccessFinancial;
    }

    /**
     * Check if the user is a super admin (has_all permissions).
     */
    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    /**
     * Get all scope node IDs (unioned across all active assignments).
     * Data queries should be filtered against these node IDs.
     *
     * @return array<int> Node IDs the user has access to
     */
    public function getScopeNodeIds(): array
    {
        // Super admins have access to all nodes
        if ($this->isSuperAdmin) {
            return []; // empty = unrestricted
        }

        return $this->scopeNodeIds;
    }

    /**
     * Check if the user can access a specific org node.
     *
     * @param int $nodeId The node ID to check
     * @return bool True if accessible
     */
    public function canAccessNode(int $nodeId): bool
    {
        if ($this->isSuperAdmin) {
            return true;
        }

        // If no scope nodes defined, deny access
        if (empty($this->scopeNodeIds)) {
            return false;
        }

        return in_array($nodeId, $this->scopeNodeIds, true);
    }

    /**
     * Get all active role assignments with role details.
     *
     * @return array Active assignments
     */
    public function getActiveAssignments(): array
    {
        return $this->activeAssignments;
    }

    /**
     * Invalidate the cached permissions (e.g. after a role change).
     */
    public function invalidate(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->loaded = false;
        $this->permissions = [];
        $this->scopeNodeIds = [];
        $this->canPublishEvents = false;
        $this->canAccessMedical = false;
        $this->canAccessFinancial = false;
        $this->isSuperAdmin = false;
        $this->activeAssignments = [];
    }

    /**
     * Load permissions from the database.
     */
    private function loadFromDatabase(int $userId): void
    {
        // Check if the user is a super admin (from users table)
        $user = $this->db->fetchOne(
            "SELECT is_super_admin FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if ($user !== null && (int) $user['is_super_admin'] === 1) {
            $this->isSuperAdmin = true;
        }

        // Load active assignments: end_date is NULL or >= today
        $assignments = $this->db->fetchAll(
            "SELECT ra.*, r.name AS role_name, r.description AS role_description,
                    r.permissions AS role_permissions,
                    r.can_publish_events, r.can_access_medical, r.can_access_financial
             FROM role_assignments ra
             JOIN roles r ON r.id = ra.role_id
             WHERE ra.user_id = :user_id
               AND (ra.end_date IS NULL OR ra.end_date >= CURDATE())
             ORDER BY ra.start_date DESC",
            ['user_id' => $userId]
        );

        $this->activeAssignments = $assignments;

        // Union permissions and flags across all assignments
        foreach ($assignments as $assignment) {
            // Parse role permissions JSON — format: {"module": ["read","write"]}
            // Expand to dot-notation keys: "module.read", "module.write"
            $rolePermissions = json_decode($assignment['role_permissions'], true) ?? [];
            foreach ($rolePermissions as $module => $actions) {
                if (is_array($actions)) {
                    foreach ($actions as $action) {
                        $this->permissions["$module.$action"] = true;
                    }
                } elseif ($actions) {
                    // Backward compat: simple key => bool
                    $this->permissions[$module] = true;
                }
            }

            // Union special flags
            if ((int) $assignment['can_publish_events'] === 1) {
                $this->canPublishEvents = true;
            }
            if ((int) $assignment['can_access_medical'] === 1) {
                $this->canAccessMedical = true;
            }
            if ((int) $assignment['can_access_financial'] === 1) {
                $this->canAccessFinancial = true;
            }

            // Load scope nodes for this assignment
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

    /**
     * Cache permissions to the session.
     */
    private function cacheToSession(int $userId): void
    {
        $this->session->set(self::SESSION_KEY, [
            'user_id' => $userId,
            'is_super_admin' => $this->isSuperAdmin,
            'permissions' => $this->permissions,
            'scope_node_ids' => $this->scopeNodeIds,
            'can_publish_events' => $this->canPublishEvents,
            'can_access_medical' => $this->canAccessMedical,
            'can_access_financial' => $this->canAccessFinancial,
            'active_assignments' => $this->activeAssignments,
        ]);
    }

    /**
     * Restore permissions from session cache.
     */
    private function restoreFromCache(array $cached): void
    {
        $this->isSuperAdmin = $cached['is_super_admin'] ?? false;
        $this->permissions = $cached['permissions'] ?? [];
        $this->scopeNodeIds = $cached['scope_node_ids'] ?? [];
        $this->canPublishEvents = $cached['can_publish_events'] ?? false;
        $this->canAccessMedical = $cached['can_access_medical'] ?? false;
        $this->canAccessFinancial = $cached['can_access_financial'] ?? false;
        $this->activeAssignments = $cached['active_assignments'] ?? [];
        $this->loaded = true;
    }
}
