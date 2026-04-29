<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Core\Database;
use App\Core\Encryption;
use App\Core\ViewContext;

/**
 * Member management service.
 *
 * Handles member CRUD, search, scope-filtered listing, membership number
 * generation, medical notes encryption/decryption, pending change management,
 * and status transitions.
 */
class MemberService
{
    private Database $db;
    private ?Encryption $encryption;

    /** @var string Prefix for auto-generated membership numbers */
    private const MEMBERSHIP_PREFIX = 'SK';

    /** @var array Fields that are safe for direct update by admins */
    private const ADMIN_UPDATABLE_FIELDS = [
        'first_name', 'surname', 'dob', 'gender', 'email', 'phone',
        'address_line1', 'address_line2', 'city', 'postcode', 'country',
        'photo_path', 'member_custom_data', 'status', 'status_reason',
        'joined_date', 'left_date', 'gdpr_consent',
    ];

    /**
     * Fields a member may suggest edits to. EVERY change submitted through
     * self-service lands in member_pending_changes and requires admin review
     * before it takes effect — no direct writes from the member side.
     *
     * Identity, contact, and address fields are included because the member
     * is the authoritative source, but approval protects against typos,
     * unauthorised account changes, and safeguarding slippage.
     */
    public const SELF_EDIT_FIELDS = [
        'first_name', 'surname', 'dob', 'gender',
        'email', 'phone',
        'address_line1', 'address_line2', 'city', 'postcode', 'country',
        'photo_path',
    ];

    /**
     * Fields that are never editable via self-service — not even as a
     * suggestion. Enforced by submitSelfEdit() rejecting unknown keys.
     *
     * - membership_number: effectively immutable once assigned
     * - medical_notes: admin-curated (safeguarding / encrypted at rest)
     * - status, status_reason, joined_date, left_date: workflow-controlled
     * - gdpr_consent: needs a proper consent-withdrawal UI, not a form edit
     * - node assignments: section/group membership is an admin decision
     * - member_custom_data: admins promote individual custom fields via
     *   custom_field_definitions once per-field self-edit flags exist
     */
    public const SELF_EDIT_NEVER = [
        'id', 'user_id', 'membership_number', 'medical_notes',
        'status', 'status_reason', 'joined_date', 'left_date',
        'gdpr_consent', 'member_custom_data',
        'created_at', 'updated_at',
    ];

    public function __construct(Database $db, ?Encryption $encryption = null)
    {
        $this->db = $db;
        $this->encryption = $encryption;
    }

    // ──── Create ────

    /**
     * Create a new member.
     *
     * @param array $data Member data
     * @return int The new member ID
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function create(array $data): int
    {
        $this->validateRequired($data);

        $membershipNumber = $data['membership_number'] ?? $this->generateMembershipNumber();

        $insertData = [
            'membership_number' => $membershipNumber,
            'first_name' => trim($data['first_name']),
            'surname' => trim($data['surname']),
            'dob' => $data['dob'] ?? null,
            'gender' => $data['gender'] ?? null,
            'email' => isset($data['email']) ? strtolower(trim($data['email'])) : null,
            'phone' => $data['phone'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'postcode' => $data['postcode'] ?? null,
            'country' => $data['country'] ?? 'Malta',
            'photo_path' => $data['photo_path'] ?? null,
            'member_custom_data' => isset($data['member_custom_data'])
                ? (is_string($data['member_custom_data']) ? $data['member_custom_data'] : json_encode($data['member_custom_data']))
                : null,
            'status' => $data['status'] ?? 'pending',
            'status_reason' => $data['status_reason'] ?? null,
            'joined_date' => $data['joined_date'] ?? null,
            'left_date' => $data['left_date'] ?? null,
            'gdpr_consent' => (int) ($data['gdpr_consent'] ?? 0),
            'user_id' => $data['user_id'] ?? null,
        ];

        // Encrypt medical notes if encryption is available
        if (!empty($data['medical_notes'])) {
            $insertData['medical_notes'] = $this->encryptMedical($data['medical_notes']);
        }

        $memberId = $this->db->insert('members', $insertData);

        // Assign to nodes
        if (!empty($data['node_ids'])) {
            $primaryNodeId = $data['primary_node_id'] ?? $data['node_ids'][0] ?? null;
            foreach ($data['node_ids'] as $nodeId) {
                $this->db->insert('member_nodes', [
                    'member_id' => $memberId,
                    'node_id' => (int) $nodeId,
                    'is_primary' => ($nodeId == $primaryNodeId) ? 1 : 0,
                ]);
            }
        }

        return $memberId;
    }

    /**
     * Create a login account for an existing member who has none.
     *
     * @param int $memberId
     * @param string $email Email for the new account (falls back to member's email)
     * @param string $password Plaintext password (min 8 chars)
     * @return int The new user ID
     * @throws \InvalidArgumentException
     */
    public function createUserAccount(int $memberId, string $email, string $password): int
    {
        $member = $this->db->fetchOne(
            "SELECT id, user_id, email FROM `members` WHERE `id` = ?",
            [$memberId]
        );
        if (!$member) {
            throw new \InvalidArgumentException("Member not found.");
        }
        if (!empty($member['user_id'])) {
            throw new \InvalidArgumentException("This member already has a login account.");
        }

        $email = strtolower(trim($email ?: (string) $member['email']));
        if ($email === '') {
            throw new \InvalidArgumentException("An email address is required to create a login account.");
        }

        if (strlen($password) < 8) {
            throw new \InvalidArgumentException("Password must be at least 8 characters.");
        }

        $existing = $this->db->fetchOne("SELECT id FROM `users` WHERE `email` = ?", [$email]);
        if ($existing) {
            throw new \InvalidArgumentException("A login account with this email address already exists.");
        }

        $userId = $this->db->insert('users', [
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'is_active' => 1,
            'password_changed_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->db->update('members', ['user_id' => $userId], ['id' => $memberId]);

        return $userId;
    }

    // ──── Read ────

    /**
     * Get a member by ID. Optionally decrypts medical notes if the caller
     * has permission and provides their user ID for access logging.
     *
     * @param int $id Member ID
     * @param bool $decryptMedical Whether to decrypt medical notes
     * @param int|null $accessedBy User ID accessing medical data (for audit)
     * @param string|null $ipAddress Client IP for audit log
     * @return array|null Member data or null if not found
     */
    public function getById(int $id, bool $decryptMedical = false, ?int $accessedBy = null, ?string $ipAddress = null): ?array
    {
        $member = $this->db->fetchOne(
            "SELECT m.*, GROUP_CONCAT(mn.node_id) AS node_ids
             FROM members m
             LEFT JOIN member_nodes mn ON mn.member_id = m.id
             WHERE m.id = :id
             GROUP BY m.id",
            ['id' => $id]
        );

        if ($member === null) {
            return null;
        }

        // Parse node IDs
        $member['node_ids'] = $member['node_ids']
            ? array_map('intval', explode(',', $member['node_ids']))
            : [];

        // Load node assignments with details
        $member['nodes'] = $this->db->fetchAll(
            "SELECT mn.*, n.name AS node_name
             FROM member_nodes mn
             JOIN org_nodes n ON n.id = mn.node_id
             WHERE mn.member_id = :id
             ORDER BY mn.is_primary DESC, n.name ASC",
            ['id' => $id]
        );

        // Handle medical notes
        if ($decryptMedical && !empty($member['medical_notes']) && $accessedBy !== null) {
            $member['medical_notes'] = $this->decryptMedical($member['medical_notes']);
            $this->logMedicalAccess($id, $accessedBy, 'view', $ipAddress);
        } elseif (!$decryptMedical) {
            // Replace with indicator that notes exist
            $member['has_medical_notes'] = !empty($member['medical_notes']);
            $member['medical_notes'] = null;
        }

        // Parse custom data
        if (!empty($member['member_custom_data'])) {
            $member['member_custom_data'] = json_decode($member['member_custom_data'], true) ?? [];
        } else {
            $member['member_custom_data'] = [];
        }

        return $this->sanitiseMember($member);
    }

    /**
     * Get a member by membership number.
     */
    public function getByMembershipNumber(string $number): ?array
    {
        $member = $this->db->fetchOne(
            "SELECT id FROM members WHERE membership_number = :num",
            ['num' => $number]
        );

        return $member ? $this->getById((int) $member['id']) : null;
    }

    // ──── Update ────

    /**
     * Update a member directly (admin update — no pending changes).
     *
     * @param int $id Member ID
     * @param array $data Fields to update
     */
    public function update(int $id, array $data): void
    {
        $updateData = [];

        foreach (self::ADMIN_UPDATABLE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];

                if ($field === 'email' && $value !== null) {
                    $value = strtolower(trim($value));
                }
                if ($field === 'member_custom_data' && is_array($value)) {
                    $value = json_encode($value);
                }

                $updateData[$field] = $value;
            }
        }

        // Handle medical notes separately (needs encryption)
        if (array_key_exists('medical_notes', $data)) {
            $updateData['medical_notes'] = !empty($data['medical_notes'])
                ? $this->encryptMedical($data['medical_notes'])
                : null;
        }

        if (!empty($updateData)) {
            $this->db->update('members', $updateData, ['id' => $id]);
        }

        // Update node assignments if provided
        if (array_key_exists('node_ids', $data)) {
            $this->updateNodeAssignments($id, $data['node_ids'] ?? [], $data['primary_node_id'] ?? null);
        }
    }

    /**
     * Submit a batch of member-initiated changes. Every supplied field that
     * differs from the current record is queued in member_pending_changes
     * for admin review. Nothing is applied to the `members` table directly.
     *
     * Unknown keys, keys in SELF_EDIT_NEVER, and no-op values are silently
     * dropped. Returns the list of field names that were queued so the caller
     * can render a confirmation.
     *
     * @param array<string, mixed> $submitted Field => proposed value
     * @return array<int, string> Field names that produced pending-change rows
     */
    public function submitSelfEdit(int $memberId, array $submitted, int $requestedBy): array
    {
        $current = $this->db->fetchOne(
            "SELECT * FROM members WHERE id = :id",
            ['id' => $memberId]
        );
        if ($current === null) {
            throw new \RuntimeException("Member not found: {$memberId}");
        }

        $queued = [];
        foreach ($submitted as $field => $rawValue) {
            if (!in_array($field, self::SELF_EDIT_FIELDS, true)) {
                continue;
            }
            $newValue = is_string($rawValue) ? trim($rawValue) : $rawValue;
            if ($newValue === '') {
                $newValue = null;
            }
            if ($field === 'email' && is_string($newValue)) {
                $newValue = strtolower($newValue);
            }
            $oldValue = $current[$field] ?? null;
            // No-op: nothing to review.
            if ((string) $oldValue === (string) $newValue) {
                continue;
            }
            // Skip if the member already has an identical pending suggestion
            // awaiting review — avoids duplicate rows on repeated submits.
            $existing = $this->db->fetchOne(
                "SELECT id FROM member_pending_changes
                  WHERE member_id = :mid AND field_name = :f
                    AND status = 'pending' AND requested_by = :uid
                    AND (new_value <=> :nv)",
                ['mid' => $memberId, 'f' => $field, 'uid' => $requestedBy, 'nv' => $newValue]
            );
            if ($existing !== null) {
                continue;
            }

            $this->createPendingChange(
                $memberId,
                $field,
                is_scalar($oldValue) ? (string) $oldValue : null,
                is_scalar($newValue) ? (string) $newValue : null,
                $requestedBy,
            );
            $queued[] = $field;
        }
        return $queued;
    }

    /**
     * Create a pending change request (for self-service edits).
     *
     * @param int $memberId Member ID
     * @param string $field Field name
     * @param string|null $oldValue Current value
     * @param string|null $newValue Requested new value
     * @param int $requestedBy User ID making the request
     * @return int Pending change ID
     */
    public function createPendingChange(int $memberId, string $field, ?string $oldValue, ?string $newValue, int $requestedBy): int
    {
        return $this->db->insert('member_pending_changes', [
            'member_id' => $memberId,
            'field_name' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'requested_by' => $requestedBy,
            'status' => 'pending',
        ]);
    }

    /**
     * Review a pending change (approve or reject).
     *
     * @param int $changeId Pending change ID
     * @param string $decision 'approved' or 'rejected'
     * @param int $reviewedBy User ID of the reviewer
     */
    public function reviewChange(int $changeId, string $decision, int $reviewedBy): void
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Decision must be "approved" or "rejected"');
        }

        $change = $this->db->fetchOne(
            "SELECT * FROM member_pending_changes WHERE id = :id AND status = 'pending'",
            ['id' => $changeId]
        );

        if ($change === null) {
            throw new \RuntimeException('Pending change not found or already reviewed');
        }

        $this->db->update('member_pending_changes', [
            'status' => $decision,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ], ['id' => $changeId]);

        // If approved, apply the change
        if ($decision === 'approved') {
            $updateData = [$change['field_name'] => $change['new_value']];

            if ($change['field_name'] === 'email' && $change['new_value'] !== null) {
                $updateData[$change['field_name']] = strtolower(trim($change['new_value']));
            }

            $this->db->update('members', $updateData, ['id' => $change['member_id']]);
        }
    }

    /**
     * Get pending changes for a member or all members.
     *
     * @param int|null $memberId Filter by member, or null for all
     * @param array $scopeNodeIds Limit to members in these nodes (empty = all)
     * @return array Pending changes with member and requester info
     */
    public function getPendingChanges(?int $memberId = null, array $scopeNodeIds = []): array
    {
        $sql = "SELECT pc.*, m.first_name, m.surname, m.membership_number,
                       u.email AS requested_by_email
                FROM member_pending_changes pc
                JOIN members m ON m.id = pc.member_id
                JOIN users u ON u.id = pc.requested_by
                WHERE pc.status = 'pending'";
        $params = [];

        if ($memberId !== null) {
            $sql .= " AND pc.member_id = :member_id";
            $params['member_id'] = $memberId;
        }

        if (!empty($scopeNodeIds)) {
            $placeholders = [];
            foreach ($scopeNodeIds as $i => $nodeId) {
                $key = "node_$i";
                $placeholders[] = ":$key";
                $params[$key] = $nodeId;
            }
            $sql .= " AND EXISTS (
                SELECT 1 FROM member_nodes mn
                WHERE mn.member_id = pc.member_id
                AND mn.node_id IN (" . implode(',', $placeholders) . ")
            )";
        }

        $sql .= " ORDER BY pc.created_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    // ──── Status ────

    /**
     * Change a member's status.
     *
     * @param int $id Member ID
     * @param string $status New status
     * @param string|null $reason Reason for the change
     */
    public function changeStatus(int $id, string $status, ?string $reason = null): void
    {
        $validStatuses = ['active', 'pending', 'suspended', 'inactive', 'left'];
        if (!in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException("Invalid status: $status");
        }

        $updateData = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        // Set left_date when leaving
        if ($status === 'left') {
            $updateData['left_date'] = date('Y-m-d');
        }

        $this->db->update('members', $updateData, ['id' => $id]);
    }

    // ──── Search and listing ────

    /**
     * Search members using FULLTEXT search.
     *
     * @param string $query Search term
     * @param array $filters Optional filters (status, node_id, etc.)
     * @param int $page Current page (1-based)
     * @param int $perPage Items per page
     * @param array $scopeNodeIds Limit to members in these nodes (empty = all)
     * @return array{items: array, total: int, page: int, per_page: int, pages: int}
     */
    public function search(string $query, array $filters = [], int $page = 1, int $perPage = 25, array $scopeNodeIds = []): array
    {
        $conditions = [];
        $params = [];

        // FULLTEXT search on name/email + LIKE fallback on membership_number for partial matches
        if ($query !== '') {
            $conditions[] = "(MATCH(m.first_name, m.surname, m.email) AGAINST(:query IN BOOLEAN MODE) OR m.membership_number LIKE :query_like)";
            $params['query'] = $query . '*';
            $params['query_like'] = '%' . $query . '%';
        }

        // Status filter
        if (!empty($filters['status'])) {
            $conditions[] = "m.status = :status";
            $params['status'] = $filters['status'];
        }

        // Node filter
        if (!empty($filters['node_id'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM member_nodes mn2 WHERE mn2.member_id = m.id AND mn2.node_id = :filter_node)";
            $params['filter_node'] = (int) $filters['node_id'];
        }

        // Scope filtering
        if (!empty($scopeNodeIds)) {
            $placeholders = [];
            foreach ($scopeNodeIds as $i => $nodeId) {
                $key = "scope_$i";
                $placeholders[] = ":$key";
                $params[$key] = $nodeId;
            }
            $conditions[] = "EXISTS (
                SELECT 1 FROM member_nodes mn3
                WHERE mn3.member_id = m.id
                AND mn3.node_id IN (" . implode(',', $placeholders) . ")
            )";
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Count total
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(DISTINCT m.id) FROM members m $where",
            $params
        );

        // Calculate pagination
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // Fetch results
        $items = $this->db->fetchAll(
            "SELECT m.*, GROUP_CONCAT(DISTINCT n.name ORDER BY mn.is_primary DESC SEPARATOR ', ') AS node_names
             FROM members m
             LEFT JOIN member_nodes mn ON mn.member_id = m.id
             LEFT JOIN org_nodes n ON n.id = mn.node_id
             $where
             GROUP BY m.id
             ORDER BY m.surname ASC, m.first_name ASC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        // Remove sensitive data from list results
        foreach ($items as &$item) {
            unset($item['medical_notes']);
            $item = $this->sanitiseMember($item);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * List members filtered by the viewer's active ViewContext.
     *
     * Expansion rules:
     *   - "All nodes" (activeScopeNodeId null) → every node the user has
     *     a scope assignment at, plus all descendants via org_closure.
     *   - Specific node → that node + all descendants via org_closure.
     *   - Empty available scopes → returns an empty page (caller should
     *     render the scope-filtered empty state).
     *
     * The actual search, filters, and pagination are delegated to search()
     * so this method stays thin.
     *
     * @param array{status?:string, node_id?:int, query?:string} $filters
     * @return array{items:array, total:int, page:int, per_page:int, pages:int}
     */
    public function listScoped(ViewContext $ctx, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $query = (string) ($filters['query'] ?? '');
        unset($filters['query']);

        $roots = $ctx->scopeNodeIds();
        // No available scopes → empty result. Happens for member-mode callers
        // or admin users with no role assignments; the controller decides
        // whether to render this or redirect.
        if ($roots === []) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 1];
        }

        $scopeNodeIds = $this->expandNodeSubtree($roots);
        return $this->search($query, $filters, $page, $perPage, $scopeNodeIds);
    }

    /**
     * Expand a list of root node IDs into the union of themselves and all
     * their descendants via org_closure. Returns a sorted, deduplicated list.
     *
     * @param array<int, int> $rootNodeIds
     * @return array<int, int>
     */
    public function expandNodeSubtree(array $rootNodeIds): array
    {
        if ($rootNodeIds === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($rootNodeIds as $i => $id) {
            $key = "root_$i";
            $placeholders[] = ":$key";
            $params[$key] = (int) $id;
        }
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT descendant_id
             FROM org_closure
             WHERE ancestor_id IN (" . implode(',', $placeholders) . ")",
            $params
        );
        return array_map('intval', array_column($rows, 'descendant_id'));
    }

    /**
     * Decide whether a given member falls inside the viewer's active scope.
     * Used by the detail view to silently redirect own-family records to
     * member mode and show a scope-error page for truly out-of-scope ones.
     */
    public function isMemberInScope(int $memberId, ViewContext $ctx): bool
    {
        if ($ctx->availableScopes === []) {
            return false;
        }
        $allowed = $this->expandNodeSubtree($ctx->scopeNodeIds());
        if ($allowed === []) {
            return false;
        }
        $placeholders = [];
        $params = ['mid' => $memberId];
        foreach ($allowed as $i => $id) {
            $key = "n_$i";
            $placeholders[] = ":$key";
            $params[$key] = $id;
        }
        $row = $this->db->fetchOne(
            "SELECT 1 FROM member_nodes
              WHERE member_id = :mid
                AND node_id IN (" . implode(',', $placeholders) . ")
              LIMIT 1",
            $params
        );
        return $row !== null;
    }

    /**
     * List members by org node, optionally including descendant nodes.
     *
     * @param int $nodeId Node ID
     * @param bool $includeDescendants Include members from descendant nodes
     * @param array $filters Optional filters (status, etc.)
     * @param int $page Current page
     * @param int $perPage Items per page
     * @return array Paginated results
     */
    public function listByNode(int $nodeId, bool $includeDescendants = false, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $scopeNodeIds = [$nodeId];

        if ($includeDescendants) {
            $descendants = $this->db->fetchAll(
                "SELECT descendant_id FROM org_closure WHERE ancestor_id = :id",
                ['id' => $nodeId]
            );
            $scopeNodeIds = array_column($descendants, 'descendant_id');
            $scopeNodeIds = array_map('intval', $scopeNodeIds);
        }

        return $this->search('', $filters, $page, $perPage, $scopeNodeIds);
    }

    // ──── Membership number ────

    /**
     * Generate a unique membership number.
     *
     * Format: SK-NNNNNN (zero-padded, auto-incrementing)
     *
     * @return string The generated membership number
     */
    public function generateMembershipNumber(): string
    {
        $maxNumber = $this->db->fetchColumn(
            "SELECT MAX(CAST(SUBSTRING(membership_number, 4) AS UNSIGNED))
             FROM members
             WHERE membership_number LIKE :prefix",
            ['prefix' => self::MEMBERSHIP_PREFIX . '-%']
        );

        $next = ($maxNumber !== null && $maxNumber !== false) ? (int) $maxNumber + 1 : 1;

        return self::MEMBERSHIP_PREFIX . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    // ──── Medical access ────

    /**
     * Log a medical data access event.
     *
     * @param int $memberId Member whose data was accessed
     * @param int $accessedBy User who accessed the data
     * @param string $action Type of access (view, update, etc.)
     * @param string|null $ipAddress Client IP
     */
    public function logMedicalAccess(int $memberId, int $accessedBy, string $action = 'view', ?string $ipAddress = null): void
    {
        $this->db->insert('medical_access_log', [
            'member_id' => $memberId,
            'accessed_by' => $accessedBy,
            'action' => $action,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Get medical access log for a member.
     *
     * @param int $memberId Member ID
     * @return array Access log entries with user details
     */
    public function getMedicalAccessLog(int $memberId): array
    {
        return $this->db->fetchAll(
            "SELECT mal.*, u.email AS accessed_by_email
             FROM medical_access_log mal
             JOIN users u ON u.id = mal.accessed_by
             WHERE mal.member_id = :id
             ORDER BY mal.created_at DESC
             LIMIT 100",
            ['id' => $memberId]
        );
    }

    // ──── Summary / counts ────

    /**
     * Get member counts grouped by status.
     *
     * @param array $scopeNodeIds Limit to nodes (empty = all)
     * @return array Status => count
     */
    public function getStatusCounts(array $scopeNodeIds = []): array
    {
        if (empty($scopeNodeIds)) {
            $rows = $this->db->fetchAll(
                "SELECT status, COUNT(*) AS cnt FROM members GROUP BY status"
            );
        } else {
            $placeholders = [];
            $params = [];
            foreach ($scopeNodeIds as $i => $id) {
                $key = "n$i";
                $placeholders[] = ":$key";
                $params[$key] = $id;
            }
            $rows = $this->db->fetchAll(
                "SELECT m.status, COUNT(DISTINCT m.id) AS cnt
                 FROM members m
                 JOIN member_nodes mn ON mn.member_id = m.id
                 WHERE mn.node_id IN (" . implode(',', $placeholders) . ")
                 GROUP BY m.status",
                $params
            );
        }

        $counts = [
            'active' => 0, 'pending' => 0, 'suspended' => 0,
            'inactive' => 0, 'left' => 0,
        ];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Get total member count.
     *
     * @param array $scopeNodeIds Limit to nodes (empty = all)
     * @return int Total count
     */
    public function getTotalCount(array $scopeNodeIds = []): int
    {
        $counts = $this->getStatusCounts($scopeNodeIds);
        return array_sum($counts);
    }

    // ──── Private helpers ────

    /**
     * Validate required fields for member creation.
     */
    private function validateRequired(array $data): void
    {
        if (empty(trim($data['first_name'] ?? ''))) {
            throw new \InvalidArgumentException('First name is required');
        }
        if (empty(trim($data['surname'] ?? ''))) {
            throw new \InvalidArgumentException('Surname is required');
        }
    }

    /**
     * Update member node assignments.
     */
    private function updateNodeAssignments(int $memberId, array $nodeIds, ?int $primaryNodeId = null): void
    {
        $this->db->delete('member_nodes', ['member_id' => $memberId]);

        if (empty($nodeIds)) {
            return;
        }

        $primaryNodeId = $primaryNodeId ?? $nodeIds[0];

        foreach ($nodeIds as $nodeId) {
            $this->db->insert('member_nodes', [
                'member_id' => $memberId,
                'node_id' => (int) $nodeId,
                'is_primary' => ($nodeId == $primaryNodeId) ? 1 : 0,
            ]);
        }
    }

    /**
     * Encrypt medical notes. Returns raw text if encryption is unavailable.
     */
    private function encryptMedical(string $text): string
    {
        if ($this->encryption !== null) {
            return $this->encryption->encrypt($text);
        }
        return $text;
    }

    /**
     * Decrypt medical notes. Returns raw text if decryption fails or encryption is unavailable.
     */
    private function decryptMedical(string $text): string
    {
        if ($this->encryption !== null) {
            try {
                return $this->encryption->decrypt($text);
            } catch (\RuntimeException $e) {
                // Data may not be encrypted (legacy or no key configured)
                return $text;
            }
        }
        return $text;
    }

    /**
     * Cast types and clean up member data for presentation.
     */
    private function sanitiseMember(array $member): array
    {
        if (isset($member['id'])) {
            $member['id'] = (int) $member['id'];
        }
        if (isset($member['user_id'])) {
            $member['user_id'] = $member['user_id'] !== null ? (int) $member['user_id'] : null;
        }
        if (isset($member['gdpr_consent'])) {
            $member['gdpr_consent'] = (bool) $member['gdpr_consent'];
        }

        return $member;
    }
}
