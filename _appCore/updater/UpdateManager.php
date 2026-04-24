<?php

/**
 * appCore — Update Manager
 *
 * Owns the steps for applying an uploaded release zip:
 *   1. Verify the single-use token
 *   2. Set maintenance mode
 *   3. Unzip the release into a staging directory
 *   4. Swap /app/ atomically (backup to /var/updates/backup-{timestamp}/)
 *   5. Run pending migrations
 *   6. Clear maintenance mode
 *
 * Rollback restores the /app/ backup if a step fails before success. This
 * is a minimal reference implementation — projects that need signed
 * releases or multi-step verification should extend it.
 */

declare(strict_types=1);

namespace Updater;

class UpdateManager
{
    private string $rootPath;
    private string $tokenFile;
    private string $stateFile;
    private string $maintenanceFlag;

    public function __construct(string $rootPath)
    {
        $this->rootPath        = rtrim($rootPath, '/\\');
        $this->tokenFile       = $this->rootPath . '/var/update_token.txt';
        $this->stateFile       = $this->rootPath . '/var/update_state.json';
        $this->maintenanceFlag = $this->rootPath . '/var/maintenance.flag';
    }

    public function verifyUpdateToken(string $token): bool
    {
        if ($token === '' || !file_exists($this->tokenFile)) {
            return false;
        }
        $stored = trim((string) file_get_contents($this->tokenFile));
        if ($stored === '' || !hash_equals($stored, $token)) {
            return false;
        }
        @unlink($this->tokenFile);
        return true;
    }

    public function setMaintenanceMode(bool $on): void
    {
        if ($on) {
            @file_put_contents($this->maintenanceFlag, gmdate('c'));
        } else {
            if (file_exists($this->maintenanceFlag)) {
                @unlink($this->maintenanceFlag);
            }
        }
    }

    /**
     * @return array{zip_path?: string, new_version?: string}
     */
    public function getUpdateState(): array
    {
        if (!file_exists($this->stateFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->stateFile), true);
        return is_array($data) ? $data : [];
    }

    public function setUpdateState(array $state): void
    {
        @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * @return array{success: bool, steps_completed: array<string>, error?: string}
     */
    public function applyUpdate(string $zipPath, ?string $newVersion): array
    {
        $steps = [];
        $staging = $this->rootPath . '/var/updates/staging_' . gmdate('Ymd_His');
        $backupDir = $this->rootPath . '/var/updates/backup_' . gmdate('Ymd_His');

        try {
            // 1. Unzip
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('ZipArchive extension is not available.');
            }
            @mkdir($staging, 0755, true);
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('Failed to open update zip.');
            }
            $zip->extractTo($staging);
            $zip->close();
            $steps[] = 'extracted';

            // 2. Back up current /app/
            if (!@rename($this->rootPath . '/app', $backupDir)) {
                throw new \RuntimeException('Failed to back up current app directory.');
            }
            $steps[] = 'backed_up';

            // 3. Swap in new /app/
            $newApp = is_dir($staging . '/app') ? $staging . '/app' : $staging;
            if (!@rename($newApp, $this->rootPath . '/app')) {
                @rename($backupDir, $this->rootPath . '/app');
                throw new \RuntimeException('Failed to swap in new app directory.');
            }
            $steps[] = 'swapped';

            // 4. Run migrations (re-bootstraps to pick up new classes)
            $this->runMigrations();
            $steps[] = 'migrated';

            // 5. Update VERSION file if supplied
            if ($newVersion !== null && $newVersion !== '') {
                @file_put_contents($this->rootPath . '/VERSION', $newVersion);
                $steps[] = 'version_written';
            }

            return ['success' => true, 'steps_completed' => $steps];
        } catch (\Throwable $e) {
            return [
                'success'         => false,
                'steps_completed' => $steps,
                'error'           => $e->getMessage(),
            ];
        }
    }

    public function rollback(): bool
    {
        $backups = glob($this->rootPath . '/var/updates/backup_*') ?: [];
        if (empty($backups)) {
            return false;
        }
        sort($backups);
        $latest = end($backups);

        if (is_dir($this->rootPath . '/app')) {
            $discard = $this->rootPath . '/var/updates/failed_' . gmdate('Ymd_His');
            @rename($this->rootPath . '/app', $discard);
        }
        return @rename($latest, $this->rootPath . '/app');
    }

    private function runMigrations(): void
    {
        $configPath = $this->rootPath . '/config/config.php';
        if (!file_exists($configPath)) {
            throw new \RuntimeException('Cannot run migrations: config.php missing.');
        }
        $config = require $configPath;

        require_once $this->rootPath . '/app/src/Core/Database.php';
        require_once $this->rootPath . '/app/src/Core/Migration.php';

        $db = new \AppCore\Core\Database($config['db']);
        (new \AppCore\Core\Migration($db, $this->rootPath . '/app/migrations'))->migrate();
    }
}
