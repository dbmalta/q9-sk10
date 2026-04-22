<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thin PDO wrapper for database operations.
 *
 * Provides parameterised queries, simple CRUD helpers, transaction support,
 * and slow query logging. All queries use prepared statements — never
 * concatenate user input into SQL.
 */
class Database
{
    private \PDO $pdo;
    private float $slowQueryThreshold;

    /** @var int Number of queries executed via this instance */
    private int $queryCount = 0;

    /** @var float Cumulative query time in ms */
    private float $queryTotalMs = 0.0;

    /** @var array<int, array{sql:string, ms:float}> Per-query samples (capped) */
    private array $querySamples = [];

    /**
     * @param array $config Database configuration array (host, port, name, user, password)
     */
    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'] ?? '3306',
            $config['name']
        );

        $this->pdo = new \PDO($dsn, $config['user'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->pdo->exec("SET NAMES utf8mb4");
        $this->slowQueryThreshold = (float) ($config['slow_query_threshold_ms'] ?? 1000);
    }

    /**
     * Execute a query with parameters and return the statement.
     *
     * @param string $sql SQL with named or positional placeholders
     * @param array $params Parameter bindings
     * @return \PDOStatement
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $start = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $elapsed = (microtime(true) - $start) * 1000;

        $this->queryCount++;
        $this->queryTotalMs += $elapsed;
        if (count($this->querySamples) < 500) {
            $this->querySamples[] = ['sql' => $sql, 'ms' => $elapsed];
        }

        if ($elapsed > $this->slowQueryThreshold) {
            $this->logSlowQuery($sql, $params, $elapsed);
        }

        return $stmt;
    }

    /**
     * @return array{count:int, total_ms:float, samples: array<int,array{sql:string,ms:float}>}
     */
    public function getProfile(): array
    {
        return [
            'count'    => $this->queryCount,
            'total_ms' => round($this->queryTotalMs, 2),
            'samples'  => $this->querySamples,
        ];
    }

    /**
     * Fetch a single row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single column value from the first row.
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * Insert a row and return the new ID.
     *
     * @param string $table Table name
     * @param array $data Column => value pairs
     * @return int The auto-increment ID
     */
    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));

        $this->query(
            "INSERT INTO `$table` ($columns) VALUES ($placeholders)",
            $data
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update rows matching the where clause.
     *
     * @param string $table Table name
     * @param array $data Column => value pairs to set
     * @param array $where Column => value conditions (AND-joined)
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $setParts[] = "`$col` = :set_$col";
            $params["set_$col"] = $val;
        }

        $whereParts = [];
        foreach ($where as $col => $val) {
            $whereParts[] = "`$col` = :where_$col";
            $params["where_$col"] = $val;
        }

        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE %s",
            $table,
            implode(', ', $setParts),
            implode(' AND ', $whereParts)
        );

        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Delete rows matching the where clause.
     *
     * @param string $table Table name
     * @param array $where Column => value conditions (AND-joined)
     * @return int Number of deleted rows
     */
    public function delete(string $table, array $where): int
    {
        $whereParts = [];
        $params = [];
        foreach ($where as $col => $val) {
            $whereParts[] = "`$col` = :$col";
            $params[$col] = $val;
        }

        return $this->query(
            sprintf("DELETE FROM `%s` WHERE %s", $table, implode(' AND ', $whereParts)),
            $params
        )->rowCount();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Get the underlying PDO instance (for advanced use or testing).
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Log a slow query to /var/logs/slow-queries.json.
     */
    private function logSlowQuery(string $sql, array $params, float $elapsedMs): void
    {
        $logFile = ROOT_PATH . '/var/logs/slow-queries.json';
        $entry = [
            'timestamp' => gmdate('c'),
            'sql' => $sql,
            'params' => $params,
            'elapsed_ms' => round($elapsedMs, 2),
        ];

        $existing = [];
        if (file_exists($logFile)) {
            $existing = json_decode(file_get_contents($logFile), true) ?? [];
        }

        $existing[] = $entry;

        // Keep last 500 entries
        if (count($existing) > 500) {
            $existing = array_slice($existing, -500);
        }

        file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));
    }
}
