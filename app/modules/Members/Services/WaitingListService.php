<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Core\Database;

/**
 * Waiting list management service.
 *
 * Manages a position-ordered queue of prospective members (typically children)
 * awaiting a place. Supports adding, reordering, status updates, and
 * converting a waiting-list entry into a full member registration.
 */
class WaitingListService
{
    private Database $db;

    /** @var array Valid status transitions */
    private const STATUS_TRANSITIONS = [
        'waiting'   => ['contacted', 'withdrawn'],
        'contacted' => ['waiting', 'converted', 'withdrawn'],
        'converted' => [],
        'withdrawn' => ['waiting'],
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Add a new entry to the waiting list.
     *
     * Automatically assigns the next position at the end of the queue.
     *
     * @param array $data Entry data (parent_name, parent_email, child_name, etc.)
     * @param int|null $preferredNodeId Preferred org node
     * @return int The new entry ID
     * @throws \InvalidArgumentException
     */
    public function addEntry(array $data, ?int $preferredNodeId = null): int
    {
        if (empty($data['parent_name']) || empty($data['parent_email']) || empty($data['child_name'])) {
            throw new \InvalidArgumentException("Parent name, parent email, and child name are required.");
        }

        $email = strtolower(trim($data['parent_email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email format.");
        }

        $position = $this->getNextPosition();

        return $this->db->insert('waiting_list', [
            'position'          => $position,
            'parent_name'       => trim($data['parent_name']),
            'parent_email'      => $email,
            'child_name'        => trim($data['child_name']),
            'child_dob'         => $data['child_dob'] ?? null,
            'preferred_node_id' => $preferredNodeId,
            'notes'             => $data['notes'] ?? null,
            'status'            => 'waiting',
        ]);
    }

    /**
     * Get the waiting list, optionally filtered by status and/or node.
     *
     * @param string|null $status Filter by status
     * @param int|null $nodeId Filter by preferred node
     * @return array
     */
    public function getList(?string $status = null, ?int $nodeId = null): array
    {
        $sql = "SELECT wl.*, n.name AS node_name
                FROM `waiting_list` wl
                LEFT JOIN `org_nodes` n ON n.id = wl.preferred_node_id
                WHERE 1=1";
        $params = [];

        if ($status !== null) {
            $sql .= " AND wl.`status` = ?";
            $params[] = $status;
        }

        if ($nodeId !== null) {
            $sql .= " AND wl.`preferred_node_id` = ?";
            $params[] = $nodeId;
        }

        $sql .= " ORDER BY wl.`position` ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get a single waiting list entry by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM `waiting_list` WHERE `id` = ?",
            [$id]
        );
        return $row ?: null;
    }

    /**
     * Reorder the waiting list by providing an ordered array of entry IDs.
     *
     * @param array $orderedIds Array of entry IDs in desired order
     */
    public function reorder(array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            $this->db->query(
                "UPDATE `waiting_list` SET `position` = ? WHERE `id` = ?",
                [$position + 1, (int) $id]
            );
        }
    }

    /**
     * Update the status of a waiting list entry.
     *
     * Enforces valid status transitions.
     *
     * @param int $id Entry ID
     * @param string $newStatus New status
     * @throws \InvalidArgumentException
     */
    public function updateStatus(int $id, string $newStatus): void
    {
        $entry = $this->getById($id);
        if (!$entry) {
            throw new \InvalidArgumentException("Waiting list entry #{$id} not found.");
        }

        $currentStatus = $entry['status'];
        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Cannot transition from '{$currentStatus}' to '{$newStatus}'."
            );
        }

        $this->db->query(
            "UPDATE `waiting_list` SET `status` = ? WHERE `id` = ?",
            [$newStatus, $id]
        );
    }

    /**
     * Update notes on a waiting list entry.
     *
     * @param int $id
     * @param string|null $notes
     */
    public function updateNotes(int $id, ?string $notes): void
    {
        $this->db->query(
            "UPDATE `waiting_list` SET `notes` = ? WHERE `id` = ?",
            [$notes, $id]
        );
    }

    /**
     * Convert a waiting list entry into a registered member.
     *
     * Creates a new member record via RegistrationService (status: pending),
     * marks the waiting list entry as 'converted', and links it to the member.
     *
     * @param int $id Waiting list entry ID
     * @param RegistrationService $registrationService
     * @return int The new member ID
     * @throws \InvalidArgumentException
     */
    public function convertToRegistration(int $id, RegistrationService $registrationService): int
    {
        $entry = $this->getById($id);
        if (!$entry) {
            throw new \InvalidArgumentException("Waiting list entry #{$id} not found.");
        }

        if ($entry['status'] === 'converted') {
            throw new \InvalidArgumentException("Entry has already been converted.");
        }

        if ($entry['status'] === 'withdrawn') {
            throw new \InvalidArgumentException("Cannot convert a withdrawn entry.");
        }

        // Build member data from waiting list entry
        $memberData = [
            'first_name' => $this->extractFirstName($entry['child_name']),
            'surname'    => $this->extractSurname($entry['child_name']),
            'dob'        => $entry['child_dob'],
            'email'      => $entry['parent_email'],
        ];

        $nodeId = $entry['preferred_node_id'] ? (int) $entry['preferred_node_id'] : null;

        $result = $registrationService->selfRegister($memberData, $nodeId);

        // Mark as converted and link to member
        $this->db->query(
            "UPDATE `waiting_list` SET `status` = 'converted', `converted_member_id` = ? WHERE `id` = ?",
            [$result['member_id'], $id]
        );

        return $result['member_id'];
    }

    /**
     * Delete a waiting list entry (only if waiting or withdrawn).
     *
     * @param int $id
     * @throws \InvalidArgumentException
     */
    public function deleteEntry(int $id): void
    {
        $entry = $this->getById($id);
        if (!$entry) {
            throw new \InvalidArgumentException("Waiting list entry #{$id} not found.");
        }

        if (!in_array($entry['status'], ['waiting', 'withdrawn'], true)) {
            throw new \InvalidArgumentException("Can only delete entries with status 'waiting' or 'withdrawn'.");
        }

        $this->db->query("DELETE FROM `waiting_list` WHERE `id` = ?", [$id]);
    }

    /**
     * Get the count of entries by status.
     *
     * @return array Associative array of status => count
     */
    public function getCountsByStatus(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT `status`, COUNT(*) AS cnt FROM `waiting_list` GROUP BY `status`"
        );
        $counts = ['waiting' => 0, 'contacted' => 0, 'converted' => 0, 'withdrawn' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }
        return $counts;
    }

    // ── Internal ─────────────────────────────────────────────────────

    /**
     * Get the next position number for a new entry.
     */
    private function getNextPosition(): int
    {
        $row = $this->db->fetchOne(
            "SELECT MAX(`position`) AS max_pos FROM `waiting_list`"
        );
        return ((int) ($row['max_pos'] ?? 0)) + 1;
    }

    /**
     * Extract the first name from a full child name.
     */
    private function extractFirstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);
        return $parts[0];
    }

    /**
     * Extract the surname from a full child name.
     * Falls back to empty string if only one name given.
     */
    private function extractSurname(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);
        return $parts[1] ?? '';
    }
}
