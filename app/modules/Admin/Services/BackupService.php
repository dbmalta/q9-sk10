<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database;

/**
 * Backup service.
 *
 * Creates, lists, and manages full system backups. Database dumps are
 * generated entirely in PHP (no CLI dependency on mysqldump) by
 * iterating every table's structure and rows. Backups are stored as
 * ZIP archives in data/backups/.
 */
class BackupService
{
    private Database $db;

    /** @var string Absolute path to the data directory */
    private string $dataPath;

    public function __construct(Database $db, string $dataPath)
    {
        $this->db = $db;
        $this->dataPath = $dataPath;
    }

    /**
     * Create a full backup (database dump + data directory contents).
     *
     * The backup is saved as data/backups/backup_YYYYMMDD_HHMMSS.zip.
     *
     * @return string The backup filename (not the full path)
     * @throws \RuntimeException If the ZIP archive cannot be created
     */
    public function createBackup(): string
    {
        $backupDir = $this->dataPath . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = 'backup_' . date('Ymd_His') . '.zip';
        $zipPath = $backupDir . '/' . $filename;

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new \RuntimeException("Failed to create backup archive: error code $result");
        }

        // Add database dump
        $sqlDump = $this->dumpDatabase();
        $zip->addFromString('database.sql', $sqlDump);

        // Add data directory contents (skip the backups directory itself)
        $this->addDirectoryToZip($zip, $this->dataPath, 'data', ['backups']);

        $zip->close();

        return $filename;
    }

    /**
     * Generate a full SQL dump of every table in the database.
     *
     * Uses SHOW TABLES and SHOW CREATE TABLE to capture structure,
     * then SELECT * to produce INSERT statements for every row.
     * No CLI tools required.
     *
     * @return string Complete SQL dump
     */
    public function dumpDatabase(): string
    {
        $lines = [];
        $lines[] = '-- ScoutKeeper database backup';
        $lines[] = '-- Generated: ' . date('Y-m-d H:i:s');
        $lines[] = '-- ----------------------------------------';
        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $lines[] = '';

        $tables = $this->db->fetchAll('SHOW TABLES');

        foreach ($tables as $tableRow) {
            $tableName = reset($tableRow); // First column value

            // Table structure
            $createResult = $this->db->fetchOne("SHOW CREATE TABLE `$tableName`");
            $createSql = $createResult['Create Table'] ?? $createResult['Create View'] ?? '';

            $lines[] = "-- Table: $tableName";
            $lines[] = "DROP TABLE IF EXISTS `$tableName`;";
            $lines[] = $createSql . ';';
            $lines[] = '';

            // Skip data for views
            if (isset($createResult['Create View'])) {
                continue;
            }

            // Table data
            $rows = $this->db->fetchAll("SELECT * FROM `$tableName`");

            if (empty($rows)) {
                $lines[] = "-- No data in $tableName";
                $lines[] = '';
                continue;
            }

            $columns = array_keys($rows[0]);
            $columnList = implode(', ', array_map(fn(string $c) => "`$c`", $columns));

            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        // Use PDO quote for proper escaping
                        $values[] = $this->db->getPdo()->quote((string) $value);
                    }
                }
                $lines[] = "INSERT INTO `$tableName` ($columnList) VALUES (" . implode(', ', $values) . ');';
            }

            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * List all backup files with metadata.
     *
     * @return array List of backups, each with 'filename', 'size', 'size_human', 'date'
     */
    public function listBackups(): array
    {
        $backupDir = $this->dataPath . '/backups';

        if (!is_dir($backupDir)) {
            return [];
        }

        $files = glob($backupDir . '/backup_*.zip');
        if ($files === false) {
            return [];
        }

        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'filename'   => basename($file),
                'size'       => filesize($file),
                'size_human' => $this->formatBytes((int) filesize($file)),
                'date'       => date('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }

        // Newest first
        usort($backups, fn(array $a, array $b) => strcmp($b['date'], $a['date']));

        return $backups;
    }

    /**
     * Delete a backup file.
     *
     * The filename is validated to prevent path traversal attacks.
     *
     * @param string $filename Backup filename (e.g. backup_20260412_120000.zip)
     * @throws \InvalidArgumentException If the filename is invalid
     * @throws \RuntimeException If the file does not exist or cannot be deleted
     */
    public function deleteBackup(string $filename): void
    {
        $this->validateFilename($filename);

        $path = $this->dataPath . '/backups/' . $filename;

        if (!file_exists($path)) {
            throw new \RuntimeException("Backup file not found: $filename");
        }

        if (!unlink($path)) {
            throw new \RuntimeException("Failed to delete backup: $filename");
        }
    }

    /**
     * Get the full path to a backup file if it exists (for download).
     *
     * @param string $filename Backup filename
     * @return string|null Full path or null if not found
     * @throws \InvalidArgumentException If the filename is invalid
     */
    public function getBackupPath(string $filename): ?string
    {
        $this->validateFilename($filename);

        $path = $this->dataPath . '/backups/' . $filename;

        return file_exists($path) ? $path : null;
    }

    // ──── Private helpers ────

    /**
     * Validate a backup filename to prevent path traversal.
     *
     * Only allows filenames matching the pattern: backup_YYYYMMDD_HHMMSS.zip
     *
     * @param string $filename Filename to validate
     * @throws \InvalidArgumentException If the filename is invalid
     */
    private function validateFilename(string $filename): void
    {
        if (!preg_match('/^backup_\d{8}_\d{6}\.zip$/', $filename)) {
            throw new \InvalidArgumentException(
                'Invalid backup filename. Expected format: backup_YYYYMMDD_HHMMSS.zip'
            );
        }

        // Extra safety: reject any path separators
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            throw new \InvalidArgumentException('Invalid characters in backup filename');
        }
    }

    /**
     * Recursively add a directory's contents to a ZIP archive.
     *
     * @param \ZipArchive $zip        The ZIP archive
     * @param string      $realPath   Absolute filesystem path
     * @param string      $zipPath    Path prefix inside the ZIP
     * @param array       $skipDirs   Directory names to skip (relative to the base)
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $realPath, string $zipPath, array $skipDirs = []): void
    {
        $items = scandir($realPath);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $realPath . '/' . $item;
            $entryPath = $zipPath . '/' . $item;

            if (is_dir($fullPath)) {
                if (in_array($item, $skipDirs, true)) {
                    continue;
                }
                $zip->addEmptyDir($entryPath);
                $this->addDirectoryToZip($zip, $fullPath, $entryPath);
            } elseif (is_file($fullPath) && is_readable($fullPath)) {
                $zip->addFile($fullPath, $entryPath);
            }
        }
    }

    /**
     * Format a byte count into a human-readable string.
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string (e.g. '2.4 MB')
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $exp = (int) floor(log($bytes, 1024));
        $exp = min($exp, count($units) - 1);

        return round($bytes / (1024 ** $exp), 1) . ' ' . $units[$exp];
    }
}
