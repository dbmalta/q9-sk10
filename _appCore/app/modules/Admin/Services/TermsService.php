<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Services;

use AppCore\Core\Database;

/**
 * Terms & Conditions versioning + user acceptances.
 */
class TermsService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT id, title, version_number, is_published, published_at, created_at
             FROM terms_versions ORDER BY id DESC"
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM terms_versions WHERE id = :id",
            ['id' => $id]
        );
    }

    public function create(string $title, string $content, string $versionNumber, int $gracePeriodDays, int $createdBy): int
    {
        return $this->db->insert('terms_versions', [
            'title'             => $title,
            'content'           => $content,
            'version_number'    => $versionNumber,
            'grace_period_days' => $gracePeriodDays,
            'is_published'      => 0,
            'created_by'        => $createdBy,
        ]);
    }

    public function update(int $id, string $title, string $content, string $versionNumber, int $gracePeriodDays): void
    {
        $this->db->update('terms_versions', [
            'title'             => $title,
            'content'           => $content,
            'version_number'    => $versionNumber,
            'grace_period_days' => $gracePeriodDays,
        ], ['id' => $id]);
    }

    public function publish(int $id): void
    {
        $this->db->update('terms_versions', [
            'is_published' => 1,
            'published_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function accept(int $versionId, int $userId, ?string $ipAddress = null): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM terms_acceptances WHERE terms_version_id = :v AND user_id = :u",
            ['v' => $versionId, 'u' => $userId]
        );
        if ($existing === null) {
            $this->db->insert('terms_acceptances', [
                'terms_version_id' => $versionId,
                'user_id'          => $userId,
                'ip_address'       => $ipAddress,
            ]);
        }
    }
}
