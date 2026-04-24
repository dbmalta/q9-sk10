<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Thin PDO wrapper.
 *
 * Provides parameterised queries, simple CRUD helpers, transaction support,
 * and slow-query profiling. Every query uses a prepared statement — never
 * concatenate user input into SQL.
 */
class Database
{
    private \PDO $pdo;
    private float $slowQueryThreshold;

    private int $queryCount = 0;
    private float $queryTotalMs = 0.0;

    /** @var array<int, array{sql:string, ms:float}> */
    private array $querySamples = [];

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'] ?? '3306',
            $config['name']
        );

        $this->pdo = new \PDO($dsn, $config['user'], $config['password'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->pdo->exec("SET NAMES utf8mb4");
        $this->slowQueryThreshold = (float) ($config['slow_query_threshold_ms'] ?? 1000);
    }

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

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

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

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    private function logSlowQuery(string $sql, array $params, float $elapsedMs): void
    {
        $logFile = ROOT_PATH . '/var/logs/slow-queries.json';
        if (!is_dir(dirname($logFile))) {
            return;
        }

        $entry = [
            'timestamp'  => gmdate('c'),
            'sql'        => $sql,
            'params'     => $params,
            'elapsed_ms' => round($elapsedMs, 2),
        ];

        $existing = [];
        if (file_exists($logFile)) {
            $existing = json_decode((string) file_get_contents($logFile), true) ?? [];
        }

        $existing[] = $entry;
        if (count($existing) > 500) {
            $existing = array_slice($existing, -500);
        }

        file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));
    }
}
