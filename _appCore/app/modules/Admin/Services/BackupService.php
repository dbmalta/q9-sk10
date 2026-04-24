<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Services;

/**
 * Database backup service.
 *
 * Produces logical backups via `mysqldump` (when available) into
 * /data/backups/. Lists, downloads, and deletes existing backups.
 */
class BackupService
{
    private string $backupDir;
    private array $dbConfig;

    public function __construct(string $backupDir, array $dbConfig)
    {
        $this->backupDir = rtrim($backupDir, '/\\');
        $this->dbConfig = $dbConfig;

        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0750, true);
        }
    }

    /**
     * @return array<array{filename: string, size: int, created_at: string}>
     */
    public function list(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }
        $files = glob($this->backupDir . '/*.sql*') ?: [];
        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'filename'   => basename($file),
                'size'       => filesize($file) ?: 0,
                'created_at' => gmdate('c', filemtime($file) ?: 0),
            ];
        }
        usort($out, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $out;
    }

    /**
     * @return array{success: bool, filename?: string, error?: string}
     */
    public function create(): array
    {
        $filename = sprintf('backup_%s.sql', gmdate('Ymd_His'));
        $path = $this->backupDir . '/' . $filename;

        $cmd = sprintf(
            'mysqldump --single-transaction -h %s -P %s -u %s %s %s > %s 2>&1',
            escapeshellarg((string) $this->dbConfig['host']),
            escapeshellarg((string) ($this->dbConfig['port'] ?? '3306')),
            escapeshellarg((string) $this->dbConfig['user']),
            empty($this->dbConfig['password']) ? '' : '-p' . escapeshellarg((string) $this->dbConfig['password']),
            escapeshellarg((string) $this->dbConfig['name']),
            escapeshellarg($path)
        );

        $exitCode = 0;
        $output = [];
        @exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($path) || filesize($path) === 0) {
            @unlink($path);
            return [
                'success' => false,
                'error'   => 'mysqldump failed: ' . implode("\n", $output),
            ];
        }

        return ['success' => true, 'filename' => $filename];
    }

    public function pathFor(string $filename): ?string
    {
        $safe = basename($filename);
        $path = $this->backupDir . '/' . $safe;
        return file_exists($path) ? $path : null;
    }

    public function delete(string $filename): bool
    {
        $path = $this->pathFor($filename);
        if ($path === null) {
            return false;
        }
        return @unlink($path);
    }
}
