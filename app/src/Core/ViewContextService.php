<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resolves the current ViewContext per request and persists user
 * preferences for mode and scope.
 *
 * Resolution precedence (URL is truth):
 *   1. URL query params ?mode= / ?scope= override everything
 *   2. Session values (set via POST /context/{mode,scope})
 *   3. User defaults (users.view_mode_last, users.scope_node_id_last)
 *   4. Derived fallback (single scope, or "All nodes")
 *
 * Every resolved scope is re-validated against a live query to
 * role_assignment_scopes — a revoked assignment silently falls back
 * to a valid scope with a one-time flash message.
 */
class ViewContextService
{
    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly ?I18n $i18n = null,
    ) {
    }

    /**
     * Translate a flash key if an I18n is available, otherwise return
     * the key verbatim (callers without i18n can translate downstream).
     */
    private function tr(string $key): string
    {
        return $this->i18n !== null ? $this->i18n->t($key) : $key;
    }

    /**
     * Resolve the ViewContext for the current request.
     */
    public function resolve(Request $request, bool $scopeAppliesToCurrentPage = true): ViewContext
    {
        $user = $this->session->getUser();
        if ($user === null) {
            // Unauthenticated — return a neutral "member" context with no scopes.
            return new ViewContext(
                mode: ViewContext::MODE_MEMBER,
                activeScopeNodeId: null,
                availableScopes: [],
                canSwitchToAdmin: false,
                canSwitchToMember: false,
                scopeAppliesToCurrentPage: false,
            );
        }

        $userId = (int) $user['id'];
        $scopes = $this->loadAvailableScopes($userId);
        $hasMember = $this->userHasMemberRecord($userId);

        $canAdmin  = count($scopes) > 0 || (bool) ($user['is_super_admin'] ?? false);
        $canMember = $hasMember;

        // --- Mode resolution ------------------------------------------------
        $mode = $this->resolveMode($request, $user, $canAdmin, $canMember);

        // --- Scope resolution ----------------------------------------------
        $activeScope = null;
        if ($mode === ViewContext::MODE_ADMIN) {
            $activeScope = $this->resolveScope($request, $user, $scopes);
        }

        return new ViewContext(
            mode: $mode,
            activeScopeNodeId: $activeScope,
            availableScopes: $scopes,
            canSwitchToAdmin: $canAdmin,
            canSwitchToMember: $canMember,
            scopeAppliesToCurrentPage: $scopeAppliesToCurrentPage,
        );
    }

    /**
     * Validate a mode value submitted via POST, persist to session + users row.
     */
    public function setMode(int $userId, string $mode): void
    {
        if ($mode !== ViewContext::MODE_ADMIN && $mode !== ViewContext::MODE_MEMBER) {
            throw new \InvalidArgumentException("Invalid mode: {$mode}");
        }
        $this->session->set('view_mode', $mode);
        $this->db->update('users', ['view_mode_last' => $mode], ['id' => $userId]);
    }

    /**
     * Validate a scope node_id against the user's role_assignment_scopes,
     * persist to session + users row. Pass null for "All nodes".
     *
     * @throws \InvalidArgumentException if the node_id is not in the user's scopes.
     */
    public function setScope(int $userId, ?int $nodeId): void
    {
        if ($nodeId !== null) {
            $scopes = $this->loadAvailableScopes($userId);
            $valid = false;
            foreach ($scopes as $s) {
                if ($s['node_id'] === $nodeId) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                throw new \InvalidArgumentException("Node {$nodeId} is not in the user's scope.");
            }
        }
        $this->session->set('active_scope_node_id', $nodeId);
        $this->db->update('users', ['scope_node_id_last' => $nodeId], ['id' => $userId]);
    }

    /**
     * Load the user's available scopes (explicit role_assignment_scopes only —
     * descendants are resolved at query time via org_closure).
     *
     * @return array<int, array{node_id:int, path:string, leaf:string}>
     */
    public function loadAvailableScopes(int $userId): array
    {
        // Display path is built from org_closure: join ancestor names in
        // depth order so "District A > Group 1 > Patrol Blue" renders cleanly.
        $rows = $this->db->fetchAll(
            "SELECT s.node_id, n.name AS leaf,
                    (
                        SELECT GROUP_CONCAT(a.name ORDER BY c.depth DESC SEPARATOR ' > ')
                        FROM org_closure c
                        INNER JOIN org_nodes a ON a.id = c.ancestor_id
                        WHERE c.descendant_id = s.node_id
                    ) AS path
             FROM role_assignment_scopes s
             INNER JOIN role_assignments ra ON ra.id = s.assignment_id
             INNER JOIN org_nodes n ON n.id = s.node_id
             WHERE ra.user_id = :uid
               AND (ra.end_date IS NULL OR ra.end_date >= CURRENT_DATE)
             GROUP BY s.node_id, n.name
             ORDER BY path",
            ['uid' => $userId]
        );

        return array_map(fn(array $r) => [
            'node_id' => (int) $r['node_id'],
            'leaf'    => (string) $r['leaf'],
            'path'    => (string) ($r['path'] ?? $r['leaf']),
        ], $rows);
    }

    private function userHasMemberRecord(int $userId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT 1 FROM members WHERE user_id = :uid LIMIT 1",
            ['uid' => $userId]
        );
        return $row !== null;
    }

    private function resolveMode(Request $request, array $user, bool $canAdmin, bool $canMember): string
    {
        // URL wins
        $urlMode = $request->getParam('mode');
        if ($urlMode === ViewContext::MODE_ADMIN && $canAdmin) {
            return ViewContext::MODE_ADMIN;
        }
        if ($urlMode === ViewContext::MODE_MEMBER && $canMember) {
            return ViewContext::MODE_MEMBER;
        }

        // Session
        $sessionMode = $this->session->get('view_mode');
        if ($sessionMode === ViewContext::MODE_ADMIN && $canAdmin) {
            return ViewContext::MODE_ADMIN;
        }
        if ($sessionMode === ViewContext::MODE_MEMBER && $canMember) {
            return ViewContext::MODE_MEMBER;
        }

        // User default
        $userDefault = $user['view_mode_last'] ?? null;
        if ($userDefault === ViewContext::MODE_ADMIN && $canAdmin) {
            return ViewContext::MODE_ADMIN;
        }
        if ($userDefault === ViewContext::MODE_MEMBER && $canMember) {
            return ViewContext::MODE_MEMBER;
        }

        // Derived fallback: prefer admin if the user has any admin capability,
        // otherwise member.
        return $canAdmin ? ViewContext::MODE_ADMIN : ViewContext::MODE_MEMBER;
    }

    /**
     * @param array<int, array{node_id:int, path:string, leaf:string}> $scopes
     */
    private function resolveScope(Request $request, array $user, array $scopes): ?int
    {
        $scopeIds = array_column($scopes, 'node_id');

        // Single-scope user: auto-select that node, ignore any override.
        if (count($scopeIds) === 1) {
            return $scopeIds[0];
        }

        $pickValidOrNull = function (mixed $raw) use ($scopeIds): ?int {
            if ($raw === 'all' || $raw === null || $raw === '') {
                return null;
            }
            $id = (int) $raw;
            return in_array($id, $scopeIds, true) ? $id : null;
        };

        // URL wins — but if URL explicitly says 'all', honour it.
        $urlScope = $request->getParam('scope');
        if ($urlScope !== null && $urlScope !== '') {
            if ($urlScope === 'all') {
                return null;
            }
            $resolved = $pickValidOrNull($urlScope);
            if ($resolved !== null) {
                return $resolved;
            }
            // Invalid URL scope: fall through, but warn via flash.
            $this->session->flash('warning', $this->tr('view.scope_fallback.revoked'));
        }

        // Session
        if (array_key_exists('active_scope_node_id', $_SESSION)) {
            $sessionScope = $_SESSION['active_scope_node_id'];
            if ($sessionScope === null) {
                return null;
            }
            $resolved = $pickValidOrNull($sessionScope);
            if ($resolved !== null) {
                return $resolved;
            }
            // Stored scope no longer valid — flash once, fall through.
            $this->session->remove('active_scope_node_id');
            $this->session->flash('warning', $this->tr('view.scope_fallback.revoked'));
        }

        // User default
        $userDefault = $user['scope_node_id_last'] ?? null;
        if ($userDefault !== null) {
            $resolved = $pickValidOrNull($userDefault);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // Final fallback: "All nodes".
        return null;
    }
}
