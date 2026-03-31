<?php

declare(strict_types=1);

namespace Core\App;

use PDO;

final class Database
{
    private ?PDO $pdo = null;

    /** @var \Core\Observability\SlowQueryLogger|null */
    private mixed $slowQueryLogger = null;

    public function __construct(private Config $config)
    {
    }

    /**
     * Attach a slow query logger for tenant-aware query latency observability (WAVE-03).
     * Called from bootstrap after the TenantContext kernel is wired.
     * Passing null removes the logger.
     */
    public function setSlowQueryLogger(?\Core\Observability\SlowQueryLogger $logger): void
    {
        $this->slowQueryLogger = $logger;
    }

    public function connection(): PDO
    {
        if ($this->pdo === null) {
            $db = $this->config->get('database');
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $db['host'],
                $db['port'],
                $db['database'],
                $db['charset']
            );
            $this->pdo = new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? (string) $key : ':' . (string) $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, $value, $type);
        }
        if ($this->slowQueryLogger !== null) {
            $start = microtime(true);
            $stmt->execute();
            $this->slowQueryLogger->observe($sql, $params, (microtime(true) - $start) * 1000.0);
        } else {
            $stmt->execute();
        }
        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->query($sql, $params)->fetch() ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('insert() requires a non-empty data array.');
        }
        $tableSql = SqlIdentifier::quoteTable($table);
        $colParts = [];
        foreach (array_keys($data) as $col) {
            if (!is_string($col)) {
                throw new \InvalidArgumentException('insert() column names must be string keys.');
            }
            $colParts[] = SqlIdentifier::quoteColumn($col);
        }
        $colList = implode(', ', $colParts);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $this->query("INSERT INTO {$tableSql} ({$colList}) VALUES ({$placeholders})", array_values($data));
        return (int) $this->connection()->lastInsertId();
    }

    public function lastInsertId(): int
    {
        return (int) $this->connection()->lastInsertId();
    }

    public function exec(string $sql): int|false
    {
        return $this->connection()->exec($sql);
    }

    /**
     * Runs $fn inside a transaction. Nests safely: inner calls do not begin/commit.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $result = $fn();
            if ($own) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
