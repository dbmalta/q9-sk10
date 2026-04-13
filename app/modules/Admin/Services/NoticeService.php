<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database;

/**
 * Notice management service.
 *
 * Handles CRUD for system-wide notices (announcements, alerts) with
 * support for mandatory acknowledgement tracking. Notices can be
 * informational (dismissible) or must_acknowledge (requiring explicit
 * user confirmation before they stop appearing).
 */
class NoticeService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ──── CRUD ────

    /**
     * Create a new notice.
     *
     * @param array $data Must include: title, content. Optional: type (default 'informational').
     * @param int $createdBy User ID of the creator
     * @return int The new notice ID
     * @throws \InvalidArgumentException if required fields are missing or type is invalid
     */
    public function create(array $data, int $createdBy): int
    {
        if (empty(trim($data['title'] ?? ''))) {
            throw new \InvalidArgumentException('Title is required');
        }
        if (empty(trim($data['content'] ?? ''))) {
            throw new \InvalidArgumentException('Content is required');
        }

        $type = $data['type'] ?? 'informational';
        if (!in_array($type, ['must_acknowledge', 'informational'], true)) {
            throw new \InvalidArgumentException('Type must be "must_acknowledge" or "informational"');
        }

        return $this->db->insert('notices', [
            'title' => trim($data['title']),
            'content' => $data['content'],
            'type' => $type,
            'is_active' => 1,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Update an existing notice.
     *
     * @param int $id Notice ID
     * @param array $data Fields to update (title, content, type)
     * @throws \InvalidArgumentException if title/content is set but empty, or type is invalid
     */
    public function update(int $id, array $data): void
    {
        $updateData = [];

        if (array_key_exists('title', $data)) {
            if (empty(trim($data['title']))) {
                throw new \InvalidArgumentException('Title cannot be empty');
            }
            $updateData['title'] = trim($data['title']);
        }

        if (array_key_exists('content', $data)) {
            if (empty(trim($data['content']))) {
                throw new \InvalidArgumentException('Content cannot be empty');
            }
            $updateData['content'] = $data['content'];
        }

        if (array_key_exists('type', $data)) {
            if (!in_array($data['type'], ['must_acknowledge', 'informational'], true)) {
                throw new \InvalidArgumentException('Type must be "must_acknowledge" or "informational"');
            }
            $updateData['type'] = $data['type'];
        }

        if (!empty($updateData)) {
            $this->db->update('notices', $updateData, ['id' => $id]);
        }
    }

    /**
     * Deactivate a notice (soft-delete).
     *
     * Sets is_active to 0, removing the notice from active listings
     * while preserving the record and its acknowledgement history.
     *
     * @param int $id Notice ID
     */
    public function deactivate(int $id): void
    {
        $this->db->update('notices', ['is_active' => 0], ['id' => $id]);
    }

    // ──── Retrieval ────

    /**
     * Get all active notices, newest first.
     *
     * @return array List of active notice records
     */
    public function getActive(): array
    {
        return $this->db->fetchAll(
            "SELECT n.*, u.email AS created_by_email
             FROM `notices` n
             LEFT JOIN `users` u ON u.id = n.created_by
             WHERE n.is_active = 1
             ORDER BY n.created_at DESC"
        );
    }

    /**
     * Get all notices (active and inactive), newest first.
     *
     * @return array List of all notice records
     */
    public function getAll(): array
    {
        return $this->db->fetchAll(
            "SELECT n.*, u.email AS created_by_email
             FROM `notices` n
             LEFT JOIN `users` u ON u.id = n.created_by
             ORDER BY n.created_at DESC"
        );
    }

    /**
     * Get a single notice by ID.
     *
     * @param int $id Notice ID
     * @return array|null The notice record, or null if not found
     */
    public function getById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT n.*, u.email AS created_by_email
             FROM `notices` n
             LEFT JOIN `users` u ON u.id = n.created_by
             WHERE n.id = :id",
            ['id' => $id]
        );
    }

    // ──── Acknowledgement ────

    /**
     * Record a user's acknowledgement of a notice.
     *
     * Uses INSERT IGNORE to silently skip if the user has already
     * acknowledged this notice (enforced by the unique key).
     *
     * @param int $noticeId Notice ID
     * @param int $userId User ID
     */
    public function acknowledge(int $noticeId, int $userId): void
    {
        $this->db->query(
            "INSERT IGNORE INTO `notice_acknowledgements` (`notice_id`, `user_id`)
             VALUES (:notice_id, :user_id)",
            [
                'notice_id' => $noticeId,
                'user_id' => $userId,
            ]
        );
    }

    /**
     * Check whether a user has acknowledged a specific notice.
     *
     * @param int $noticeId Notice ID
     * @param int $userId User ID
     * @return bool True if the user has acknowledged
     */
    public function hasAcknowledged(int $noticeId, int $userId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT 1 FROM `notice_acknowledgements`
             WHERE `notice_id` = :notice_id AND `user_id` = :user_id",
            ['notice_id' => $noticeId, 'user_id' => $userId]
        );

        return $row !== null;
    }

    /**
     * Get active must_acknowledge notices that a user has not yet acknowledged.
     *
     * These are the notices that should be displayed as blocking prompts
     * until the user explicitly acknowledges them.
     *
     * @param int $userId User ID
     * @return array List of unacknowledged notice records
     */
    public function getUnacknowledgedForUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT n.*
             FROM `notices` n
             WHERE n.is_active = 1
               AND n.type = 'must_acknowledge'
               AND NOT EXISTS (
                   SELECT 1 FROM `notice_acknowledgements` na
                   WHERE na.notice_id = n.id AND na.user_id = :user_id
               )
             ORDER BY n.created_at DESC",
            ['user_id' => $userId]
        );
    }

    /**
     * Get the acknowledgement report for a specific notice.
     *
     * Returns a list of users who acknowledged, with their email and
     * acknowledgement timestamp.
     *
     * @param int $noticeId Notice ID
     * @return array List of acknowledgement records with user details
     */
    public function getAcknowledgementReport(int $noticeId): array
    {
        return $this->db->fetchAll(
            "SELECT na.*, u.email AS user_email
             FROM `notice_acknowledgements` na
             JOIN `users` u ON u.id = na.user_id
             WHERE na.notice_id = :notice_id
             ORDER BY na.acknowledged_at DESC",
            ['notice_id' => $noticeId]
        );
    }
}
