<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Core\Database;

/**
 * Registration service.
 *
 * Handles self-registration (public), invitation-based registration,
 * and admin approval/rejection of pending registrations. Works with
 * MemberService for member creation and AuthService for user accounts.
 */
class RegistrationService
{
    private Database $db;

    /** @var int Invitation token validity in hours */
    private const INVITATION_EXPIRY_HOURS = 168; // 7 days

    /** @var int Token length in bytes (produces 64-char hex string) */
    private const TOKEN_BYTES = 32;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ── Self-Registration ────────────────────────────────────────────

    /**
     * Process a self-registration request.
     *
     * Creates a member with status 'pending' and optionally a user account.
     * Does NOT send email — caller is responsible for queueing confirmation.
     *
     * @param array $data Member data (first_name, surname, email, etc.)
     * @param int|null $nodeId Target org node (optional)
     * @param string|null $password If provided, creates a user account
     * @return array{member_id: int, user_id: int|null}
     * @throws \InvalidArgumentException
     */
    public function selfRegister(array $data, ?int $nodeId = null, ?string $password = null): array
    {
        if (empty($data['first_name']) || empty($data['surname'])) {
            throw new \InvalidArgumentException("First name and surname are required.");
        }
        if (empty($data['email'])) {
            throw new \InvalidArgumentException("Email is required for self-registration.");
        }

        $email = strtolower(trim($data['email']));

        // Check for duplicate email in members
        $existing = $this->db->fetchOne(
            "SELECT id FROM `members` WHERE `email` = ?",
            [$email]
        );
        if ($existing) {
            throw new \InvalidArgumentException("A member with this email already exists.");
        }

        $userId = null;

        // Create user account if password provided
        if ($password !== null && strlen($password) >= 8) {
            // Check for duplicate user email
            $existingUser = $this->db->fetchOne(
                "SELECT id FROM `users` WHERE `email` = ?",
                [$email]
            );
            if ($existingUser) {
                throw new \InvalidArgumentException("A user account with this email already exists.");
            }

            $userId = $this->db->insert('users', [
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'is_active' => 0, // Inactive until approved
                'password_changed_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        // Generate membership number
        $membershipNumber = $this->generateMembershipNumber();

        // Create member record
        $memberId = $this->db->insert('members', [
            'membership_number' => $membershipNumber,
            'first_name' => trim($data['first_name']),
            'surname' => trim($data['surname']),
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'dob' => $data['dob'] ?? null,
            'gender' => $data['gender'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'postcode' => $data['postcode'] ?? null,
            'country' => $data['country'] ?? null,
            'status' => 'pending',
            'user_id' => $userId,
            'gdpr_consent' => (int) ($data['gdpr_consent'] ?? 0),
        ]);

        // Assign to node if specified
        if ($nodeId !== null) {
            $this->db->query(
                "INSERT INTO `member_nodes` (`member_id`, `node_id`, `is_primary`) VALUES (?, ?, 1)",
                [$memberId, $nodeId]
            );
        }

        return ['member_id' => $memberId, 'user_id' => $userId];
    }

    /**
     * Approve a pending registration.
     *
     * Sets member status to 'active', activates user account if linked.
     *
     * @param int $memberId
     * @param int $approvedBy User ID of the approver
     * @throws \InvalidArgumentException
     */
    public function approveRegistration(int $memberId, int $approvedBy): void
    {
        $member = $this->db->fetchOne(
            "SELECT * FROM `members` WHERE `id` = ? AND `status` = 'pending'",
            [$memberId]
        );
        if (!$member) {
            throw new \InvalidArgumentException("Member #{$memberId} not found or not pending.");
        }

        $this->db->query(
            "UPDATE `members` SET `status` = 'active', `joined_date` = CURDATE() WHERE `id` = ?",
            [$memberId]
        );

        // Activate linked user account
        if (!empty($member['user_id'])) {
            $this->db->query(
                "UPDATE `users` SET `is_active` = 1 WHERE `id` = ?",
                [$member['user_id']]
            );
        }
    }

    /**
     * Reject a pending registration.
     *
     * Sets member status to 'inactive'. Does NOT delete the record.
     *
     * @param int $memberId
     * @param int $rejectedBy User ID
     * @param string|null $reason
     * @throws \InvalidArgumentException
     */
    public function rejectRegistration(int $memberId, int $rejectedBy, ?string $reason = null): void
    {
        $member = $this->db->fetchOne(
            "SELECT * FROM `members` WHERE `id` = ? AND `status` = 'pending'",
            [$memberId]
        );
        if (!$member) {
            throw new \InvalidArgumentException("Member #{$memberId} not found or not pending.");
        }

        $this->db->query(
            "UPDATE `members` SET `status` = 'inactive', `status_reason` = ? WHERE `id` = ?",
            [$reason, $memberId]
        );

        // Deactivate linked user account
        if (!empty($member['user_id'])) {
            $this->db->query(
                "UPDATE `users` SET `is_active` = 0 WHERE `id` = ?",
                [$member['user_id']]
            );
        }
    }

    /**
     * Get pending registrations, optionally scoped to nodes.
     *
     * @param array $scopeNodeIds Empty = unrestricted
     * @return array
     */
    public function getPendingRegistrations(array $scopeNodeIds = []): array
    {
        $sql = "SELECT m.*, GROUP_CONCAT(mn.node_id) AS node_ids
                FROM `members` m
                LEFT JOIN `member_nodes` mn ON mn.member_id = m.id
                WHERE m.`status` = 'pending'";
        $params = [];

        if (!empty($scopeNodeIds)) {
            $placeholders = implode(',', array_fill(0, count($scopeNodeIds), '?'));
            $sql .= " AND EXISTS (
                SELECT 1 FROM `member_nodes` mn2
                WHERE mn2.member_id = m.id AND mn2.node_id IN ({$placeholders})
            )";
            $params = array_merge($params, $scopeNodeIds);
        }

        $sql .= " GROUP BY m.id ORDER BY m.created_at ASC";

        return $this->db->fetchAll($sql, $params);
    }

    // ── Invitations ──────────────────────────────────────────────────

    /**
     * Create an invitation token for a specific org node.
     *
     * @param int $nodeId Target org node
     * @param int $createdBy User ID who created the invitation
     * @param string|null $email Optional target email
     * @param int $expiryHours Hours until expiry (default: 168 = 7 days)
     * @return string The invitation token
     */
    public function createInvitation(int $nodeId, int $createdBy, ?string $email = null, int $expiryHours = self::INVITATION_EXPIRY_HOURS): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($expiryHours * 3600));

        $this->db->insert('registration_invitations', [
            'token' => $token,
            'target_node_id' => $nodeId,
            'created_by' => $createdBy,
            'email' => $email ? strtolower(trim($email)) : null,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Validate and retrieve an invitation by token.
     *
     * @param string $token
     * @return array|null The invitation record, or null if invalid/expired/used
     */
    public function getValidInvitation(string $token): ?array
    {
        $invitation = $this->db->fetchOne(
            "SELECT * FROM `registration_invitations`
             WHERE `token` = ? AND `used_at` IS NULL AND `expires_at` > NOW()",
            [$token]
        );

        return $invitation ?: null;
    }

    /**
     * Process an invitation — registers the member and marks the invitation as used.
     *
     * @param string $token Invitation token
     * @param array $data Member data
     * @param string|null $password Optional password for user account
     * @return array{member_id: int, user_id: int|null}
     * @throws \InvalidArgumentException
     */
    public function processInvitation(string $token, array $data, ?string $password = null): array
    {
        $invitation = $this->getValidInvitation($token);
        if (!$invitation) {
            throw new \InvalidArgumentException("Invalid or expired invitation.");
        }

        // If invitation has a target email, verify it matches
        if (!empty($invitation['email']) && !empty($data['email'])) {
            if (strtolower(trim($data['email'])) !== strtolower($invitation['email'])) {
                throw new \InvalidArgumentException("Email does not match the invitation.");
            }
        }

        $result = $this->selfRegister($data, (int) $invitation['target_node_id'], $password);

        // Mark invitation as used
        $this->db->query(
            "UPDATE `registration_invitations` SET `used_at` = NOW() WHERE `id` = ?",
            [$invitation['id']]
        );

        return $result;
    }

    /**
     * Get all invitations for a node, optionally including expired/used.
     *
     * @param int $nodeId
     * @param bool $activeOnly
     * @return array
     */
    public function getInvitations(int $nodeId, bool $activeOnly = true): array
    {
        $sql = "SELECT ri.*, u.email AS creator_email
                FROM `registration_invitations` ri
                LEFT JOIN `users` u ON u.id = ri.created_by
                WHERE ri.`target_node_id` = ?";
        $params = [$nodeId];

        if ($activeOnly) {
            $sql .= " AND ri.`used_at` IS NULL AND ri.`expires_at` > NOW()";
        }

        $sql .= " ORDER BY ri.`created_at` DESC";

        return $this->db->fetchAll($sql, $params);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Generate a unique membership number (SK-NNNNNN format).
     */
    private function generateMembershipNumber(): string
    {
        $row = $this->db->fetchOne(
            "SELECT MAX(CAST(SUBSTRING(membership_number, 4) AS UNSIGNED)) AS max_num FROM `members`"
        );
        $next = ((int) ($row['max_num'] ?? 0)) + 1;
        return 'SK-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
