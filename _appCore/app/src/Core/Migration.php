<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Forward-only SQL migration runner.
 *
 * Reads numbered *.sql files from /app/migrations/ in filename order,
 * applies pending ones, and records each as a row in the `_migrations`
 * table. DDL in MySQL is auto-committed per statement, so partial
 * failures can leave schema mid-migration — keep one concern per file.
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
     * @return array<string>
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
     * @return array<string>
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
     * @return array<string>
     */
    public function getPending(): array
    {
        $applied = $this->getApplied();
        $all = $this->getMigrationFiles();
        return array_values(array_diff($all, $applied));
    }

    /**
     * @return array<string> Newly applied filenames
     */
    public function migrate(): array
    {
        $applied = [];
        foreach ($this->getPending() as $filename) {
            $this->applyMigration($filename);
            $applied[] = $filename;
        }
        return $applied;
    }

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

        try {
            foreach ($this->splitStatements($sql) as $statement) {
                if (trim($statement) !== '') {
                    $this->db->query($statement);
                }
            }

            $this->db->insert('_migrations', [
                'filename'   => $filename,
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Migration failed ($filename): " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Split SQL text into individual statements, respecting strings and
     * `-- line comments`.
     *
     * @return array<string>
     */
    private function splitStatements(string $sql): array
    {
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

            if ($char === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
                $end = strpos($sql, "\n", $i);
                if ($end === false) {
                    break;
                }
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
     * @return array<array{filename: string, status: string, applied_at: string|null}>
     */
    public function getStatus(): array
    {
        $applied = $this->db->fetchAll("SELECT filename, applied_at FROM _migrations ORDER BY id");
        $appliedMap = [];
        foreach ($applied as $row) {
            $appliedMap[$row['filename']] = $row['applied_at'];
        }

        $status = [];
        foreach ($this->getMigrationFiles() as $file) {
            $status[] = [
                'filename'   => $file,
                'status'     => isset($appliedMap[$file]) ? 'applied' : 'pending',
                'applied_at' => $appliedMap[$file] ?? null,
            ];
        }
        return $status;
    }
}
