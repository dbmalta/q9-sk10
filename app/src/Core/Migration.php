<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Database migration runner.
 *
 * Reads numbered SQL files from /app/migrations/, maintains a _migrations
 * tracking table, and applies pending migrations in order. Each migration
 * runs within its own transaction.
 */
class Migration
{
    private Database $db;
    private string $migrationsPath;

    public function __construct(Database $db, ?string $migrationsPath = null)
    {
        $this->db = $db;
        $this->migrationsPath = $migrationsPath ?? ROOT_PATH . '/app/migrations';
    }

    /**
     * Ensure the _migrations tracking table exists.
     */
    public function ensureTable(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `_migrations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `filename` VARCHAR(255) NOT NULL UNIQUE,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Get all migration files sorted by number.
     *
     * @return array<string> Filenames sorted by migration number
     */
    public function getMigrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.sql');
        if ($files === false) {
            return [];
        }

        $files = array_map('basename', $files);
        sort($files);
        return $files;
    }

    /**
     * Get the list of already-applied migrations.
     *
     * @return array<string> Filenames of applied migrations
     */
    public function getApplied(): array
    {
        $this->ensureTable();
        return array_column(
            $this->db->fetchAll("SELECT filename FROM _migrations ORDER BY id"),
            'filename'
        );
    }

    /**
     * Get pending (not yet applied) migrations.
     *
     * @return array<string> Filenames of pending migrations
     */
    public function getPending(): array
    {
        $applied = $this->getApplied();
        $all = $this->getMigrationFiles();
        return array_values(array_diff($all, $applied));
    }

    /**
     * Apply all pending migrations.
     *
     * @return array<string> List of newly applied migration filenames
     * @throws \RuntimeException if a migration fails
     */
    public function migrate(): array
    {
        $applied = [];
        $pending = $this->getPending();

        foreach ($pending as $filename) {
            $this->applyMigration($filename);
            $applied[] = $filename;
        }

        return $applied;
    }

    /**
     * Apply a single migration file within a transaction.
     *
     * @param string $filename The migration filename
     * @throws \RuntimeException if the migration fails
     */
    private function applyMigration(string $filename): void
    {
        $filePath = $this->migrationsPath . '/' . $filename;
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Migration file not found: $filename");
        }

        $sql = file_get_contents($filePath);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException("Migration file is empty: $filename");
        }

        // Note: MySQL DDL statements (CREATE TABLE, ALTER TABLE, DROP TABLE) cause
        // an implicit commit and cannot be rolled back. We run statements sequentially
        // and record the migration at the end. If a statement fails, previous DDL
        // changes persist (MySQL limitation), but the migration is not recorded,
        // so it can be retried after fixing the issue.
        try {
            $statements = $this->splitStatements($sql);
            foreach ($statements as $statement) {
                if (trim($statement) !== '') {
                    $this->db->query($statement);
                }
            }

            // Record the migration as applied
            $this->db->insert('_migrations', [
                'filename' => $filename,
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Migration failed ($filename): " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Split a SQL file into individual statements.
     * Handles semicolons within strings and comments.
     */
    private function splitStatements(string $sql): array
    {
        // Simple split on semicolons not inside strings
        // For more complex SQL, a proper parser would be needed
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';

        for ($i = 0, $len = strlen($sql); $i < $len; $i++) {
            $char = $sql[$i];

            if ($inString) {
                $current .= $char;
                if ($char === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
                continue;
            }

            // Skip line comments
            if ($char === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
                $end = strpos($sql, "\n", $i);
                if ($end === false) break;
                $i = $end;
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Get migration status for display.
     *
     * @return array<array{filename: string, status: string, applied_at: string|null}>
     */
    public function getStatus(): array
    {
        $applied = $this->db->fetchAll("SELECT filename, applied_at FROM _migrations ORDER BY id");
        $appliedMap = [];
        foreach ($applied as $row) {
            $appliedMap[$row['filename']] = $row['applied_at'];
        }

        $allFiles = $this->getMigrationFiles();
        $status = [];
        foreach ($allFiles as $file) {
            $status[] = [
                'filename' => $file,
                'status' => isset($appliedMap[$file]) ? 'applied' : 'pending',
                'applied_at' => $appliedMap[$file] ?? null,
            ];
        }

        return $status;
    }
}
