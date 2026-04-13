<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Response;
use App\Core\Request;

/**
 * Monitoring API controller.
 *
 * Provides JSON endpoints for external monitoring and log retrieval:
 *   - GET /api/health  (public)  -- system health overview
 *   - GET /api/logs    (API key) -- recent errors and slow queries
 *
 * The /api/health endpoint is a lightweight in-app version of
 * the standalone /health.php. The /api/logs endpoint requires
 * authentication via the X-API-Key header, checked against the
 * `api_key` value in the `settings` table.
 */
class MonitoringController extends Controller
{
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    /**
     * GET /api/health -- Public JSON health check.
     *
     * Returns status, version, PHP version, DB connectivity,
     * memory peak, last cron run, and error count.
     */
    public function health(Request $request, array $vars): Response
    {
        $config = $this->app->getConfig();

        $health = [
            'status' => 'ok',
            'timestamp' => gmdate('c'),
            'version' => $this->getAppVersion(),
            'php_version' => PHP_VERSION,
            'memory_peak' => round(memory_get_peak_usage(true) / 1048576, 2) . ' MB',
        ];

        // Database check
        try {
            $this->app->getDb()->fetchColumn("SELECT 1");
            $health['db_status'] = 'connected';
        } catch (\Throwable) {
            $health['status'] = 'degraded';
            $health['db_status'] = 'disconnected';
        }

        // Last cron run
        $health['last_cron_run'] = $this->getLastCronRun();

        // Error count
        $health['error_count'] = $this->getErrorCount();

        return Response::json($health);
    }

    /**
     * GET /api/logs -- Authenticated log retrieval.
     *
     * Requires X-API-Key header matching the `api_key` setting.
     * Supports ?since=ISO8601 to filter entries after a given timestamp.
     *
     * Returns recent errors and slow queries as JSON.
     */
    public function logs(Request $request, array $vars): Response
    {
        // Authenticate via API key
        $authResult = $this->authenticateApiKey($request);
        if ($authResult !== null) {
            return $authResult;
        }

        $since = $request->getParam('since');
        $sinceTimestamp = null;
        if ($since !== null && $since !== '') {
            $parsed = strtotime((string) $since);
            if ($parsed !== false) {
                $sinceTimestamp = $parsed;
            }
        }

        $errors = $this->readLogFile(ROOT_PATH . '/var/logs/errors.json', $sinceTimestamp);
        $slowQueries = $this->readLogFile(ROOT_PATH . '/var/logs/slow-queries.json', $sinceTimestamp);

        return Response::json([
            'timestamp' => gmdate('c'),
            'errors' => [
                'count' => count($errors),
                'entries' => array_slice(array_reverse($errors), 0, 100),
            ],
            'slow_queries' => [
                'count' => count($slowQueries),
                'entries' => array_slice(array_reverse($slowQueries), 0, 100),
            ],
        ]);
    }

    // ── Private Helpers ──────────────────────────────────────────────

    /**
     * Authenticate the request using the X-API-Key header.
     *
     * Returns null if authenticated, or an error Response if not.
     */
    private function authenticateApiKey(Request $request): ?Response
    {
        $providedKey = $request->getHeader('X-API-KEY') ?? '';

        if ($providedKey === '') {
            return Response::json(
                ['error' => 'Missing X-API-Key header.'],
                401
            );
        }

        // Read the stored API key from the settings table
        $storedKey = null;
        try {
            $row = $this->app->getDb()->fetchOne(
                "SELECT `value` FROM `settings` WHERE `key` = 'api_key'"
            );
            $storedKey = $row['value'] ?? null;
        } catch (\Throwable) {
            return Response::json(
                ['error' => 'Unable to verify API key.'],
                500
            );
        }

        if ($storedKey === null || $storedKey === '') {
            return Response::json(
                ['error' => 'API key not configured. Set it in the admin panel.'],
                503
            );
        }

        if (!hash_equals($storedKey, $providedKey)) {
            return Response::json(
                ['error' => 'Invalid API key.'],
                403
            );
        }

        return null; // Authenticated
    }

    /**
     * Read and optionally filter a JSON log file.
     *
     * @param string $filePath Absolute path to the log file
     * @param int|null $sinceTimestamp Only return entries after this Unix timestamp
     * @return array Filtered log entries
     */
    private function readLogFile(string $filePath, ?int $sinceTimestamp = null): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false || $content === '') {
            return [];
        }

        $entries = json_decode($content, true);
        if (!is_array($entries)) {
            return [];
        }

        if ($sinceTimestamp === null) {
            return $entries;
        }

        return array_values(array_filter($entries, function (array $entry) use ($sinceTimestamp): bool {
            $ts = $entry['timestamp'] ?? null;
            if ($ts === null) {
                return false;
            }
            $entryTime = strtotime($ts);
            return $entryTime !== false && $entryTime >= $sinceTimestamp;
        }));
    }

    /**
     * Get the application version from the settings table.
     */
    private function getAppVersion(): string
    {
        try {
            $row = $this->app->getDb()->fetchOne(
                "SELECT `value` FROM `settings` WHERE `key` = 'app_version'"
            );
            if ($row && !empty($row['value'])) {
                return $row['value'];
            }
        } catch (\Throwable) {
            // Fall through
        }

        return $this->app->getConfigValue('app.version', 'dev');
    }

    /**
     * Get the last cron run timestamp.
     */
    private function getLastCronRun(): ?string
    {
        // Check data/cron_state.json first
        $cronStateFile = ROOT_PATH . '/data/cron_state.json';
        if (file_exists($cronStateFile)) {
            $state = json_decode(file_get_contents($cronStateFile), true);
            if (is_array($state) && isset($state['last_run'])) {
                return $state['last_run'];
            }
        }

        // Fall back to var/cache/cron_last_run.txt
        $cronLastRunFile = ROOT_PATH . '/var/cache/cron_last_run.txt';
        if (file_exists($cronLastRunFile)) {
            $ts = trim(file_get_contents($cronLastRunFile));
            if (is_numeric($ts)) {
                return gmdate('c', (int) $ts);
            }
            return $ts ?: null;
        }

        return null;
    }

    /**
     * Get the total error count from log files.
     */
    private function getErrorCount(): int
    {
        $count = 0;

        $files = [
            ROOT_PATH . '/var/logs/errors.json',
            ROOT_PATH . '/data/logs/errors.json',
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $entries = json_decode(file_get_contents($file), true);
                if (is_array($entries)) {
                    $count += count($entries);
                }
            }
        }

        return $count;
    }
}
