<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database;

/**
 * Audit log service.
 *
 * Records and retrieves audit trail entries for all system actions.
 * Sensitive field values (passwords, medical data, MFA secrets,
 * encryption keys) are automatically redacted before storage.
 */
class AuditService
{
    private Database $db;

    /** @var array Substrings that trigger value redaction in old/new values */
    private const SENSITIVE_PATTERNS = [
        'password',
        'medical',
        'mfa_secret',
        'encryption',
    ];

    private const REDACTED = '[REDACTED]';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Record an audit log entry.
     *
     * Values in $oldValues and $newValues whose keys contain sensitive
     * substrings (password, medical, mfa_secret, encryption) are
     * automatically replaced with '[REDACTED]'.
     *
     * @param string      $action    Action performed (e.g. 'create', 'update', 'delete', 'login')
     * @param string      $entityType Entity type (e.g. 'member', 'user', 'setting')
     * @param int|null    $entityId   Entity ID (null for non-entity actions)
     * @param array|null  $oldValues  Previous field values (null for create)
     * @param array|null  $newValues  New field values (null for delete)
     * @param int|null    $userId     Acting user ID
     * @param string|null $ip         Client IP address
     * @param string|null $userAgent  Client user agent string
     */
    public function log(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        $this->db->insert('audit_log', [
            'user_id'    => $userId,
            'action'     => $action,
            'entity_type' => $entityType,
            'entity_id'  => $entityId,
            'old_values'  => $oldValues !== null ? json_encode($this->redact($oldValues)) : null,
            'new_values'  => $newValues !== null ? json_encode($this->redact($newValues)) : null,
            'ip_address'  => $ip,
            'user_agent'  => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ]);
    }

    /**
     * Get paginated, optionally filtered audit log entries.
     *
     * Joins the users table to include the acting user's email address.
     *
     * @param int         $page       Page number (1-based)
     * @param int         $perPage    Items per page
     * @param string|null $entityType Filter by entity type
     * @param int|null    $userId     Filter by user
     * @param string|null $action     Filter by action
     * @param string|null $dateFrom   Start date (Y-m-d)
     * @param string|null $dateTo     End date (Y-m-d)
     * @return array{items: array, total: int, page: int, pages: int, per_page: int}
     */
    public function getLog(
        int $page = 1,
        int $perPage = 25,
        ?string $entityType = null,
        ?int $userId = null,
        ?string $action = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $conditions = [];
        $params = [];

        if ($entityType !== null) {
            $conditions[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }

        if ($userId !== null) {
            $conditions[] = 'a.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        if ($action !== null) {
            $conditions[] = 'a.action = :action';
            $params['action'] = $action;
        }

        if ($dateFrom !== null) {
            $conditions[] = 'a.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $conditions[] = 'a.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Count total
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM audit_log a $where",
            $params
        );

        // Pagination
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        // Fetch with user email
        $items = $this->db->fetchAll(
            "SELECT a.*, u.email AS user_email
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             $where
             ORDER BY a.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
        );

        // Decode JSON columns
        foreach ($items as &$item) {
            $item['old_values'] = $item['old_values'] !== null
                ? json_decode($item['old_values'], true)
                : null;
            $item['new_values'] = $item['new_values'] !== null
                ? json_decode($item['new_values'], true)
                : null;
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get all audit entries for a specific entity, newest first.
     *
     * @param string $entityType Entity type
     * @param int    $entityId   Entity ID
     * @return array Audit log entries with user email
     */
    public function getEntityTrail(string $entityType, int $entityId): array
    {
        $items = $this->db->fetchAll(
            "SELECT a.*, u.email AS user_email
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = :entity_type AND a.entity_id = :entity_id
             ORDER BY a.created_at DESC",
            ['entity_type' => $entityType, 'entity_id' => $entityId]
        );

        foreach ($items as &$item) {
            $item['old_values'] = $item['old_values'] !== null
                ? json_decode($item['old_values'], true)
                : null;
            $item['new_values'] = $item['new_values'] !== null
                ? json_decode($item['new_values'], true)
                : null;
        }

        return $items;
    }

    /**
     * Get distinct action values from the audit log (for filter dropdowns).
     *
     * @return array List of action strings
     */
    public function getActions(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT action FROM audit_log ORDER BY action ASC"
        );

        return array_column($rows, 'action');
    }

    /**
     * Get distinct entity_type values from the audit log (for filter dropdowns).
     *
     * @return array List of entity type strings
     */
    public function getEntityTypes(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type ASC"
        );

        return array_column($rows, 'entity_type');
    }

    // ──── Private helpers ────

    /**
     * Redact sensitive values from an associative array.
     *
     * Any key that contains one of the sensitive patterns (case-insensitive)
     * has its value replaced with '[REDACTED]'.
     *
     * @param array $data Key-value pairs to inspect
     * @return array Redacted copy
     */
    private function redact(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            $keyLower = strtolower((string) $key);
            $isSensitive = false;

            foreach (self::SENSITIVE_PATTERNS as $pattern) {
                if (str_contains($keyLower, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            $redacted[$key] = $isSensitive ? self::REDACTED : $value;
        }

        return $redacted;
    }
}
