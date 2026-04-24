<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Services;

use AppCore\Core\Database;

/**
 * Dashboard statistics.
 *
 * Returns aggregate counts and recent activity for the admin landing page.
 * Add project-specific figures (members, events, etc.) by extending this
 * service in the project layer.
 */
class DashboardService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{user_count: int, recent_logins: array, recent_audit: array}
     */
    public function getStats(): array
    {
        return [
            'user_count'    => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM users"),
            'recent_logins' => $this->db->fetchAll(
                "SELECT id, email, last_login_at FROM users
                 WHERE last_login_at IS NOT NULL
                 ORDER BY last_login_at DESC LIMIT 10"
            ),
            'recent_audit'  => $this->db->fetchAll(
                "SELECT a.action, a.entity_type, a.entity_id, a.created_at, u.email
                 FROM audit_log a
                 LEFT JOIN users u ON u.id = a.user_id
                 ORDER BY a.id DESC LIMIT 10"
            ),
        ];
    }

    /**
     * @return array{php_version: string, app_version: string, db_size: string}
     */
    public function getSystemHealth(): array
    {
        $sizeRow = $this->db->fetchOne(
            "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS size_mb
             FROM information_schema.tables
             WHERE table_schema = DATABASE()"
        );
        $sizeMb = $sizeRow !== null ? (string) $sizeRow['size_mb'] : '0';

        $versionFile = defined('ROOT_PATH') ? ROOT_PATH . '/VERSION' : __DIR__ . '/../../../../VERSION';
        $appVersion = trim((string) @file_get_contents($versionFile) ?: '');

        return [
            'php_version' => PHP_VERSION,
            'app_version' => $appVersion,
            'db_size'     => $sizeMb . ' MB',
        ];
    }
}
