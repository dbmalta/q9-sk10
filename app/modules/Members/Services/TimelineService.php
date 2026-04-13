<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Core\Database;

/**
 * Member timeline service.
 *
 * Manages time-series data entries for members — rank progressions,
 * qualification dates, and other historical records keyed by field_key.
 */
class TimelineService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Add a timeline entry for a member.
     *
     * @param int $memberId
     * @param string $fieldKey Category key (e.g. 'rank', 'qualification')
     * @param string $value The value/label (e.g. 'Scout', 'First Aid')
     * @param string $effectiveDate Date the entry takes effect (YYYY-MM-DD)
     * @param int|null $recordedBy User ID who recorded this entry
     * @param string|null $notes Optional notes
     * @return int Inserted ID
     * @throws \InvalidArgumentException
     */
    public function addEntry(
        int $memberId,
        string $fieldKey,
        string $value,
        string $effectiveDate,
        ?int $recordedBy = null,
        ?string $notes = null
    ): int {
        if (empty($fieldKey)) {
            throw new \InvalidArgumentException("Field key is required.");
        }
        if (empty($value)) {
            throw new \InvalidArgumentException("Value is required.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            throw new \InvalidArgumentException("Effective date must be in YYYY-MM-DD format.");
        }

        $this->db->query(
            "INSERT INTO `member_timeline`
             (`member_id`, `field_key`, `value`, `effective_date`, `recorded_by`, `notes`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$memberId, $fieldKey, $value, $effectiveDate, $recordedBy, $notes]
        );

        return $this->db->lastInsertId();
    }

    /**
     * Get all timeline entries for a member, optionally filtered by field key.
     *
     * Results are sorted by effective_date DESC (newest first), then by id DESC.
     *
     * @param int $memberId
     * @param string|null $fieldKey Filter by field key (null = all entries)
     * @return array
     */
    public function getEntries(int $memberId, ?string $fieldKey = null): array
    {
        $sql = "SELECT t.*, u.email AS recorder_email
                FROM `member_timeline` t
                LEFT JOIN `users` u ON u.id = t.recorded_by
                WHERE t.`member_id` = ?";
        $params = [$memberId];

        if ($fieldKey !== null) {
            $sql .= " AND t.`field_key` = ?";
            $params[] = $fieldKey;
        }

        $sql .= " ORDER BY t.`effective_date` DESC, t.`id` DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get the latest (most recent effective_date) timeline entry for a member and field key.
     *
     * @param int $memberId
     * @param string $fieldKey
     * @return array|null
     */
    public function getLatestEntry(int $memberId, string $fieldKey): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT t.*, u.email AS recorder_email
             FROM `member_timeline` t
             LEFT JOIN `users` u ON u.id = t.recorded_by
             WHERE t.`member_id` = ? AND t.`field_key` = ?
             ORDER BY t.`effective_date` DESC, t.`id` DESC
             LIMIT 1",
            [$memberId, $fieldKey]
        );

        return $row ?: null;
    }

    /**
     * Get a single timeline entry by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM `member_timeline` WHERE `id` = ?",
            [$id]
        );
        return $row ?: null;
    }

    /**
     * Delete a timeline entry.
     *
     * @param int $id Entry ID
     * @return bool True if a row was deleted
     */
    public function deleteEntry(int $id): bool
    {
        $stmt = $this->db->query(
            "DELETE FROM `member_timeline` WHERE `id` = ?",
            [$id]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * Get all distinct field keys used in timeline entries for a member.
     *
     * @param int $memberId
     * @return array List of field key strings
     */
    public function getFieldKeys(int $memberId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT `field_key` FROM `member_timeline`
             WHERE `member_id` = ?
             ORDER BY `field_key` ASC",
            [$memberId]
        );

        return array_column($rows, 'field_key');
    }

    /**
     * Get timeline entries grouped by field key for a member.
     *
     * @param int $memberId
     * @return array Associative array: field_key => entries[]
     */
    public function getEntriesGrouped(int $memberId): array
    {
        $entries = $this->getEntries($memberId);
        $grouped = [];

        foreach ($entries as $entry) {
            $grouped[$entry['field_key']][] = $entry;
        }

        return $grouped;
    }
}
