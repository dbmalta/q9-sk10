<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Services;

use AppCore\Core\Database;

/**
 * Broadcast notices + acknowledgements.
 *
 * Admins create notices (informational or mandatory). Users acknowledge
 * them once per notice.
 */
class NoticeService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT id, title, type, is_active, created_at, updated_at
             FROM notices ORDER BY is_active DESC, id DESC"
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, title, content, type, is_active FROM notices WHERE id = :id",
            ['id' => $id]
        );
    }

    public function create(string $title, string $content, string $type, int $createdBy): int
    {
        return $this->db->insert('notices', [
            'title'      => $title,
            'content'    => $content,
            'type'       => in_array($type, ['must_acknowledge', 'informational'], true) ? $type : 'informational',
            'is_active'  => 1,
            'created_by' => $createdBy,
        ]);
    }

    public function update(int $id, string $title, string $content, string $type): void
    {
        $this->db->update('notices', [
            'title'   => $title,
            'content' => $content,
            'type'    => in_array($type, ['must_acknowledge', 'informational'], true) ? $type : 'informational',
        ], ['id' => $id]);
    }

    public function deactivate(int $id): void
    {
        $this->db->update('notices', ['is_active' => 0], ['id' => $id]);
    }

    public function acknowledge(int $noticeId, int $userId): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM notice_acknowledgements WHERE notice_id = :n AND user_id = :u",
            ['n' => $noticeId, 'u' => $userId]
        );
        if ($existing === null) {
            $this->db->insert('notice_acknowledgements', [
                'notice_id' => $noticeId,
                'user_id'   => $userId,
            ]);
        }
    }

    public function getUnacknowledgedForUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT n.id, n.title, n.content, n.type
             FROM notices n
             LEFT JOIN notice_acknowledgements na
                    ON na.notice_id = n.id AND na.user_id = :uid
             WHERE n.is_active = 1 AND na.id IS NULL
             ORDER BY n.id DESC",
            ['uid' => $userId]
        );
    }
}
