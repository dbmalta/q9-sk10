<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database;

/**
 * Dashboard statistics and system health service.
 *
 * Aggregates key metrics from across the application for the admin
 * dashboard: member counts, event summaries, waiting list status,
 * pending changes, and system health indicators.
 */
class DashboardService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Get aggregate statistics for the admin dashboard.
     *
     * @return array{
     *     total_members: int,
     *     members_by_status: array<string, int>,
     *     members_by_gender: array<string, int>,
     *     recent_registrations: array,
     *     upcoming_events: array,
     *     waiting_list_count: int,
     *     pending_changes_count: int,
     * }
     */
    public function getStats(): array
    {
        return [
            'total_members' => $this->getTotalMembers(),
            'members_by_status' => $this->getMembersByStatus(),
            'members_by_gender' => $this->getMembersByGender(),
            'recent_registrations' => $this->getRecentRegistrations(),
            'upcoming_events' => $this->getUpcomingEvents(),
            'waiting_list_count' => $this->getWaitingListCount(),
            'pending_changes_count' => $this->getPendingChangesCount(),
        ];
    }

    /**
     * Get system health indicators.
     *
     * @return array{
     *     php_version: string,
     *     db_size: string,
     *     last_cron_run: string|null,
     *     error_count: int,
     *     app_version: string,
     * }
     */
    public function getSystemHealth(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'db_size' => $this->getDatabaseSize(),
            'last_cron_run' => $this->getLastCronRun(),
            'error_count' => $this->getErrorCount(),
            'app_version' => $this->getAppVersion(),
        ];
    }

    // ──── Stats helpers ─────────────────────────────────────────────

    /**
     * Get total member count.
     */
    private function getTotalMembers(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM `members`");
    }

    /**
     * Get member counts grouped by status.
     *
     * @return array<string, int> Status => count (always includes all statuses)
     */
    private function getMembersByStatus(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT `status`, COUNT(*) AS cnt FROM `members` GROUP BY `status`"
        );

        $counts = [
            'active' => 0,
            'pending' => 0,
            'suspended' => 0,
            'inactive' => 0,
            'left' => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Get member counts grouped by gender.
     *
     * @return array<string, int> Gender label => count
     */
    private function getMembersByGender(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT COALESCE(`gender`, 'Unknown') AS gender_label, COUNT(*) AS cnt
             FROM `members`
             GROUP BY gender_label"
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['gender_label']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Get the 10 most recent pending member registrations.
     *
     * @return array List of member records (id, first_name, surname, email, created_at)
     */
    private function getRecentRegistrations(): array
    {
        return $this->db->fetchAll(
            "SELECT `id`, `first_name`, `surname`, `email`, `membership_number`, `created_at`
             FROM `members`
             WHERE `status` = 'pending'
             ORDER BY `created_at` DESC
             LIMIT 10"
        );
    }

    /**
     * Get the next 5 upcoming published events.
     *
     * @return array List of event records (id, title, start_date, location)
     */
    private function getUpcomingEvents(): array
    {
        return $this->db->fetchAll(
            "SELECT `id`, `title`, `start_date`, `end_date`, `location`
             FROM `events`
             WHERE `is_published` = 1 AND `start_date` >= NOW()
             ORDER BY `start_date` ASC
             LIMIT 5"
        );
    }

    /**
     * Get the count of entries currently on the waiting list (status = 'waiting').
     */
    private function getWaitingListCount(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM `waiting_list` WHERE `status` = 'waiting'"
        );
    }

    /**
     * Get the count of pending member change requests.
     */
    private function getPendingChangesCount(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM `member_pending_changes` WHERE `status` = 'pending'"
        );
    }

    // ──── System health helpers ─────────────────────────────────────

    /**
     * Get the total database size from information_schema.
     *
     * @return string Human-readable size (e.g. "12.4 MB")
     */
    private function getDatabaseSize(): string
    {
        $row = $this->db->fetchOne(
            "SELECT SUM(data_length + index_length) AS total_bytes
             FROM information_schema.TABLES
             WHERE table_schema = DATABASE()"
        );

        $bytes = (int) ($row['total_bytes'] ?? 0);

        return $this->formatBytes($bytes);
    }

    /**
     * Read the last cron run timestamp from the state file.
     *
     * @return string|null ISO 8601 timestamp, or null if no cron has run
     */
    private function getLastCronRun(): ?string
    {
        $stateFile = (defined('ROOT_PATH') ? ROOT_PATH : '.') . '/data/cron_state.json';

        if (!file_exists($stateFile)) {
            return null;
        }

        $state = json_decode(file_get_contents($stateFile), true);

        return $state['last_run'] ?? null;
    }

    /**
     * Count error entries in the error log.
     *
     * Reads the JSON error log and returns the number of entries.
     * Returns 0 if the log file does not exist.
     *
     * @return int Number of logged errors
     */
    private function getErrorCount(): int
    {
        $logFile = (defined('ROOT_PATH') ? ROOT_PATH : '.') . '/data/logs/errors.json';

        if (!file_exists($logFile)) {
            return 0;
        }

        $content = file_get_contents($logFile);
        if ($content === false || $content === '') {
            return 0;
        }

        $entries = json_decode($content, true);

        return is_array($entries) ? count($entries) : 0;
    }

    /**
     * Get the application version.
     *
     * Reads from data/version.json if it exists (set by the updater),
     * otherwise falls back to a default.
     *
     * @return string The application version string
     */
    private function getAppVersion(): string
    {
        $versionFile = (defined('ROOT_PATH') ? ROOT_PATH : '.') . '/data/version.json';

        if (file_exists($versionFile)) {
            $data = json_decode(file_get_contents($versionFile), true);
            if (isset($data['version'])) {
                return $data['version'];
            }
        }

        return '0.1.9';
    }

    /**
     * Format a byte count into a human-readable string.
     *
     * @param int $bytes Raw byte count
     * @return string Formatted string (e.g. "12.4 MB")
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}
