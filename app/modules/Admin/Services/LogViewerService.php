<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

/**
 * Log viewer service.
 *
 * Reads, paginates, and clears the JSON-based application log files
 * (errors, slow queries, cron). Each file is a JSON array of objects.
 * Missing or unreadable files are handled gracefully with empty results.
 */
class LogViewerService
{
    /** @var string Absolute path to the data directory */
    private string $dataPath;

    /** @var array<string, string> Map of log type to filename */
    private const LOG_FILES = [
        'errors'       => 'errors.json',
        'slow-queries' => 'slow-queries.json',
        'cron'         => 'cron.json',
    ];

    /**
     * @param string $dataPath Path to the data directory (e.g. ROOT_PATH . '/data')
     */
    public function __construct(string $dataPath)
    {
        $this->dataPath = $dataPath;
    }

    /**
     * Get paginated error log entries.
     *
     * @param int $page    Page number (1-based)
     * @param int $perPage Items per page
     * @return array{items: array, total: int, page: int, pages: int}
     */
    public function getErrors(int $page = 1, int $perPage = 50): array
    {
        return $this->paginateLog('errors', $page, $perPage);
    }

    /**
     * Get paginated slow query log entries.
     *
     * @param int $page    Page number (1-based)
     * @param int $perPage Items per page
     * @return array{items: array, total: int, page: int, pages: int}
     */
    public function getSlowQueries(int $page = 1, int $perPage = 50): array
    {
        return $this->paginateLog('slow-queries', $page, $perPage);
    }

    /**
     * Get paginated cron log entries.
     *
     * @param int $page    Page number (1-based)
     * @param int $perPage Items per page
     * @return array{items: array, total: int, page: int, pages: int}
     */
    public function getCronLog(int $page = 1, int $perPage = 50): array
    {
        return $this->paginateLog('cron', $page, $perPage);
    }

    /**
     * Clear a specific log file by writing an empty JSON array.
     *
     * @param string $type Log type: 'errors', 'slow-queries', or 'cron'
     * @throws \InvalidArgumentException If the type is not recognised
     */
    public function clearLog(string $type): void
    {
        if (!isset(self::LOG_FILES[$type])) {
            throw new \InvalidArgumentException(
                sprintf('Invalid log type "%s". Must be one of: %s', $type, implode(', ', array_keys(self::LOG_FILES)))
            );
        }

        $path = $this->getLogPath($type);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents($path, '[]', LOCK_EX);
    }

    /**
     * Get the count of entries in each log file.
     *
     * @return array<string, int> Map of log type to entry count
     */
    public function getLogCounts(): array
    {
        $counts = [];

        foreach (array_keys(self::LOG_FILES) as $type) {
            $entries = $this->readLog($type);
            $counts[$type] = count($entries);
        }

        return $counts;
    }

    // ──── Private helpers ────

    /**
     * Read and paginate a log file.
     *
     * Entries are returned in reverse order (newest first) to match
     * the typical admin expectation of seeing recent entries at the top.
     *
     * @param string $type    Log type key
     * @param int    $page    Page number (1-based)
     * @param int    $perPage Items per page
     * @return array{items: array, total: int, page: int, pages: int}
     */
    private function paginateLog(string $type, int $page, int $perPage): array
    {
        $entries = $this->readLog($type);

        // Reverse so newest entries come first
        $entries = array_reverse($entries);

        $total = count($entries);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $items = array_slice($entries, $offset, $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /**
     * Read a log file and return its decoded entries.
     *
     * Returns an empty array if the file does not exist, is unreadable,
     * or contains invalid JSON.
     *
     * @param string $type Log type key
     * @return array Decoded log entries
     */
    private function readLog(string $type): array
    {
        $path = $this->getLogPath($type);

        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build the full filesystem path for a log type.
     *
     * @param string $type Log type key
     * @return string Absolute file path
     */
    private function getLogPath(string $type): string
    {
        return $this->dataPath . '/logs/' . self::LOG_FILES[$type];
    }
}
