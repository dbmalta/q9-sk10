<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Services;

use AppCore\Core\Database;

/**
 * Append-only audit log.
 *
 * Call `log()` from the service layer on every state-changing mutation.
 * Never edit or delete rows. Encrypted columns should be represented by
 * a marker string (e.g. '[encrypted]'), never plaintext.
 */
class AuditService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function log(
        string $entityType,
        ?int $entityId,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?int $userId,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): int {
        return $this->db->insert('audit_log', [
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            'new_values'  => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent,
        ]);
    }

    /**
     * @return array<array>
     */
    public function recent(int $limit = 100, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT a.*, u.email AS user_email
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC
             LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset)
        );
    }

    public function count(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM audit_log");
    }
}
