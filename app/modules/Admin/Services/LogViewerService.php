<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

/**
 * Log viewer service.
 *
 * Reads, paginates, filters, and clears the JSON-based application log files
 * under var/logs/ (errors, slow queries, cron runs, app info/debug).
 * Each file is a JSON array of objects. Missing or unreadable files are
 * handled gracefully with empty results.
 */
class LogViewerService
{
    /** @var string Absolute path to var/logs */
    private string $logsPath;

    /** @var array<string, string> Map of log type to filename */
    private const LOG_FILES = [
        'errors'       => 'errors.json',
        'requests'     => 'requests.json',
        'slow-queries' => 'slow-queries.json',
        'cron'         => 'cron.json',
        'app'          => 'app.json',
        'smtp'         => 'smtp.json',
    ];

    /**
     * @param string $rootPath Application root (ROOT_PATH). Logs live at $rootPath/var/logs.
     */
    public function __construct(string $rootPath)
    {
        $this->logsPath = rtrim($rootPath, '/\\') . '/var/logs';
    }

    /**
     * Valid log type keys.
     *
     * @return string[]
     */
    public static function types(): array
    {
        return array_keys(self::LOG_FILES);
    }

    /**
     * Get paginated, filtered entries for a given log type.
     *
     * Supported filters (all optional):
     *   - level:   string — matches entry.level exactly (errors/app only)
     *   - status:  string — matches entry.status exactly (cron only)
     *   - min_ms:  float  — minimum elapsed_ms (slow-queries only)
     *   - search:  string — case-insensitive substring match across common fields
     *
     * @param string              $type    Log type key
     * @param int                 $page    Page number (1-based)
     * @param int                 $perPage Items per page
     * @param array<string,mixed> $filters Filters to apply
     * @return array{items: array, total: int, total_unfiltered: int, page: int, pages: int}
     */
    public function getEntries(string $type, int $page = 1, int $perPage = 50, array $filters = []): array
    {
        $all = $this->readLog($type);
        $totalUnfiltered = count($all);

        $filtered = $this->applyFilters($all, $filters);

        // Newest first
        $filtered = array_reverse($filtered);

        $total = count($filtered);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        return [
            'items'            => array_slice($filtered, $offset, $perPage),
            'total'            => $total,
            'total_unfiltered' => $totalUnfiltered,
            'page'             => $page,
            'pages'            => $pages,
        ];
    }

    /**
     * Summary statistics for the slow-query log.
     *
     * @param array<string,mixed> $filters Same filters as getEntries
     * @return array{count:int, avg_ms:float, p50_ms:float, p95_ms:float, max_ms:float, top: array<int,array{sql:string,count:int,avg_ms:float,max_ms:float}>}
     */
    public function getSlowQueryStats(array $filters = []): array
    {
        $entries = $this->applyFilters($this->readLog('slow-queries'), $filters);

        $durations = [];
        $grouped = [];
        foreach ($entries as $e) {
            $ms = (float) ($e['elapsed_ms'] ?? 0);
            $durations[] = $ms;
            $sql = $this->normaliseSql((string) ($e['sql'] ?? ''));
            if ($sql === '') {
                continue;
            }
            if (!isset($grouped[$sql])) {
                $grouped[$sql] = ['sql' => $sql, 'count' => 0, 'sum' => 0.0, 'max' => 0.0];
            }
            $grouped[$sql]['count']++;
            $grouped[$sql]['sum'] += $ms;
            if ($ms > $grouped[$sql]['max']) {
                $grouped[$sql]['max'] = $ms;
            }
        }

        sort($durations);
        $count = count($durations);
        $percentile = static function (array $sorted, float $p): float {
            if ($sorted === []) {
                return 0.0;
            }
            $idx = (int) floor(($p / 100) * (count($sorted) - 1));
            return round($sorted[$idx], 2);
        };

        $top = array_map(static function ($g) {
            return [
                'sql'    => $g['sql'],
                'count'  => $g['count'],
                'avg_ms' => round($g['sum'] / max(1, $g['count']), 2),
                'max_ms' => round($g['max'], 2),
            ];
        }, array_values($grouped));
        usort($top, static fn($a, $b) => $b['count'] <=> $a['count'] ?: $b['avg_ms'] <=> $a['avg_ms']);
        $top = array_slice($top, 0, 10);

        return [
            'count'  => $count,
            'avg_ms' => $count ? round(array_sum($durations) / $count, 2) : 0.0,
            'p50_ms' => $percentile($durations, 50),
            'p95_ms' => $percentile($durations, 95),
            'max_ms' => $count ? round(max($durations), 2) : 0.0,
            'top'    => $top,
        ];
    }

    /**
     * Clear a specific log file by writing an empty JSON array.
     *
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
     * @return array<string, int>
     */
    public function getLogCounts(): array
    {
        $counts = [];
        foreach (array_keys(self::LOG_FILES) as $type) {
            $counts[$type] = count($this->readLog($type));
        }
        return $counts;
    }

    // ──── Private helpers ────

    /**
     * @param array<int,array<string,mixed>> $entries
     * @param array<string,mixed>            $filters
     * @return array<int,array<string,mixed>>
     */
    private function applyFilters(array $entries, array $filters): array
    {
        $level  = isset($filters['level'])  ? (string) $filters['level']  : '';
        $status = isset($filters['status']) ? (string) $filters['status'] : '';
        $minMs  = isset($filters['min_ms']) ? (float)  $filters['min_ms'] : 0.0;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $searchLc = $search !== '' ? mb_strtolower($search) : '';

        if ($level === '' && $status === '' && $minMs <= 0 && $searchLc === '') {
            return $entries;
        }

        return array_values(array_filter($entries, static function ($e) use ($level, $status, $minMs, $searchLc) {
            if ($level !== '' && (string) ($e['level'] ?? '') !== $level) {
                return false;
            }
            if ($status !== '' && (string) ($e['status'] ?? '') !== $status) {
                return false;
            }
            if ($minMs > 0) {
                $ms = (float) ($e['elapsed_ms'] ?? $e['wall_ms'] ?? 0);
                if ($ms < $minMs) {
                    return false;
                }
            }
            if ($searchLc !== '') {
                $haystack = mb_strtolower(json_encode($e, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
                if (strpos($haystack, $searchLc) === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Collapse whitespace and strip string/number literals so structurally
     * identical queries (with different bound values) group together.
     */
    private function normaliseSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        return trim($sql);
    }

    private function readLog(string $type): array
    {
        if (!isset(self::LOG_FILES[$type])) {
            return [];
        }
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

    private function getLogPath(string $type): string
    {
        return $this->logsPath . '/' . self::LOG_FILES[$type];
    }
}
