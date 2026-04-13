<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Core\Logger;

/**
 * Pseudo-cron service for shared hosting environments.
 *
 * Manages scheduled task execution without CLI cron access. Tasks are
 * registered with an interval, and dispatch() runs any that are due.
 * A state file tracks last-run times, and a lock file prevents
 * concurrent execution. Can be triggered via page loads or a web-
 * accessible cron endpoint.
 */
class CronService
{
    /** @var string Path to the cron state file */
    private string $stateFile;

    /** @var string Path to the cron lock file */
    private string $lockFile;

    /** @var string Path to the cron log file */
    private string $logFile;

    /** @var array<string, array{handler: callable, interval: int}> Registered tasks */
    private array $tasks = [];

    /**
     * @param string $dataPath Path to the data directory (e.g. ROOT_PATH . '/data')
     */
    public function __construct(string $dataPath)
    {
        $this->stateFile = $dataPath . '/cron_state.json';
        $this->lockFile = $dataPath . '/cron.lock';
        $this->logFile = $dataPath . '/logs/cron.json';
    }

    /**
     * Register a task to be run on a schedule.
     *
     * @param string $name Unique task identifier (e.g. 'email_queue')
     * @param callable $handler Callable that executes the task; should return a result array
     * @param int $intervalMinutes Minimum minutes between runs
     */
    public function registerTask(string $name, callable $handler, int $intervalMinutes = 60): void
    {
        $this->tasks[$name] = [
            'handler' => $handler,
            'interval' => $intervalMinutes,
        ];
    }

    /**
     * Run all registered tasks that are due.
     *
     * Acquires a lock file to prevent concurrent execution. Each task is
     * checked individually against its interval. Results and errors are
     * logged to data/logs/cron.json.
     *
     * @return array<string, array{status: string, result?: mixed, error?: string}>
     */
    public function dispatch(): array
    {
        // Acquire lock
        if (!$this->acquireLock()) {
            Logger::warning('Cron dispatch skipped: lock file exists (concurrent run)');
            return ['_skipped' => ['status' => 'locked']];
        }

        $results = [];

        try {
            foreach ($this->tasks as $name => $task) {
                if (!$this->shouldRun($name)) {
                    $results[$name] = ['status' => 'skipped', 'reason' => 'not due'];
                    continue;
                }

                try {
                    $result = ($task['handler'])();
                    $this->updateTaskLastRun($name);
                    $results[$name] = ['status' => 'completed', 'result' => $result];
                } catch (\Throwable $e) {
                    $results[$name] = ['status' => 'error', 'error' => $e->getMessage()];
                    Logger::error("Cron task '$name' failed", [
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }
            }

            // Update global last_run
            $this->updateGlobalLastRun();

            // Log the run
            $this->logRun($results);
        } finally {
            $this->releaseLock();
        }

        return $results;
    }

    /**
     * Check whether a specific task should run based on its interval.
     *
     * @param string $taskName Task identifier
     * @return bool True if the task is due to run
     */
    public function shouldRun(string $taskName): bool
    {
        if (!isset($this->tasks[$taskName])) {
            return false;
        }

        $state = $this->readState();
        $taskState = $state['tasks'][$taskName] ?? null;

        if ($taskState === null || !isset($taskState['last_run'])) {
            return true; // Never run before
        }

        $lastRun = strtotime($taskState['last_run']);
        if ($lastRun === false) {
            return true;
        }

        $interval = $this->tasks[$taskName]['interval'];
        $nextDue = $lastRun + ($interval * 60);

        return time() >= $nextDue;
    }

    /**
     * Get the last run information from the state file.
     *
     * @return array|null State data or null if no state file exists
     */
    public function getLastRun(): ?array
    {
        $state = $this->readState();
        return !empty($state) ? $state : null;
    }

    /**
     * Pseudo-cron check intended to be called on page loads.
     *
     * Triggers dispatch() if enough time has elapsed since the last
     * global run. Uses a minimum interval of 1 minute to avoid
     * hammering on every request.
     */
    public function pseudoCronCheck(): void
    {
        $state = $this->readState();
        $lastRun = $state['last_run'] ?? null;

        if ($lastRun !== null) {
            $lastRunTime = strtotime($lastRun);
            if ($lastRunTime !== false && (time() - $lastRunTime) < 60) {
                return; // Less than 1 minute since last run
            }
        }

        // Run in the background-ish: dispatch but don't block the page
        $this->dispatch();
    }

    // ──── State management ────

    /**
     * Read the cron state file.
     *
     * @return array The state data
     */
    private function readState(): array
    {
        if (!file_exists($this->stateFile)) {
            return [];
        }

        $contents = file_get_contents($this->stateFile);
        if ($contents === false) {
            return [];
        }

        return json_decode($contents, true) ?? [];
    }

    /**
     * Write data to the cron state file.
     */
    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * Update the last_run timestamp for a specific task.
     */
    private function updateTaskLastRun(string $taskName): void
    {
        $state = $this->readState();

        if (!isset($state['tasks'])) {
            $state['tasks'] = [];
        }

        $state['tasks'][$taskName] = [
            'last_run' => gmdate('c'),
            'interval' => $this->tasks[$taskName]['interval'] ?? 60,
        ];

        $this->writeState($state);
    }

    /**
     * Update the global last_run timestamp.
     */
    private function updateGlobalLastRun(): void
    {
        $state = $this->readState();
        $state['last_run'] = gmdate('c');
        $this->writeState($state);
    }

    // ──── Locking ────

    /**
     * Acquire the lock file. Returns false if another process holds the lock.
     *
     * The lock file contains a timestamp. If the lock is older than 10 minutes,
     * it is considered stale and will be removed.
     */
    private function acquireLock(): bool
    {
        if (file_exists($this->lockFile)) {
            $lockContent = file_get_contents($this->lockFile);
            $lockTime = (int) $lockContent;

            // Stale lock (> 10 minutes old)
            if ($lockTime > 0 && (time() - $lockTime) > 600) {
                @unlink($this->lockFile);
            } else {
                return false;
            }
        }

        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents($this->lockFile, (string) time(), LOCK_EX);
        return true;
    }

    /**
     * Release the lock file.
     */
    private function releaseLock(): void
    {
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    // ──── Logging ────

    /**
     * Log the results of a cron dispatch to data/logs/cron.json.
     *
     * @param array $results Task results from dispatch()
     */
    private function logRun(array $results): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $entries = [];
        if (file_exists($this->logFile)) {
            $contents = file_get_contents($this->logFile);
            if ($contents !== false) {
                $entries = json_decode($contents, true) ?? [];
            }
        }

        $entries[] = [
            'timestamp' => gmdate('c'),
            'tasks' => $results,
        ];

        // Keep last 200 entries
        if (count($entries) > 200) {
            $entries = array_slice($entries, -200);
        }

        file_put_contents(
            $this->logFile,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
