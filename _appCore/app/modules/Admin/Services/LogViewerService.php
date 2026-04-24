<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Services;

/**
 * Read and clear JSON log files from /var/logs/.
 *
 * Files handled: errors, app, slow-queries, requests, smtp, cron, updates.
 * Older rotated files (.1.json, .2.json, ...) are listed but not merged.
 */
class LogViewerService
{
    private string $logDir;

    private const KNOWN_TYPES = [
        'errors', 'app', 'slow-queries', 'requests', 'smtp', 'cron', 'updates',
    ];

    public function __construct(string $logDir)
    {
        $this->logDir = rtrim($logDir, '/\\');
    }

    /**
     * @return array<string>
     */
    public static function knownTypes(): array
    {
        return self::KNOWN_TYPES;
    }

    /**
     * Load entries from /var/logs/{type}.json (most recent first).
     *
     * @return array<int, array>
     */
    public function read(string $type, int $limit = 500): array
    {
        if (!in_array($type, self::KNOWN_TYPES, true)) {
            return [];
        }
        $path = $this->logDir . '/' . $type . '.json';
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        return array_slice(array_reverse($data), 0, max(1, $limit));
    }

    public function clear(string $type): bool
    {
        if (!in_array($type, self::KNOWN_TYPES, true)) {
            return false;
        }
        $path = $this->logDir . '/' . $type . '.json';
        if (!file_exists($path)) {
            return true;
        }
        return file_put_contents($path, '[]', LOCK_EX) !== false;
    }
}
