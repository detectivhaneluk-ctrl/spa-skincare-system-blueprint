<?php

declare(strict_types=1);

namespace Core\App;

use PDO;

final class Database
{
    private ?PDO $pdo = null;

    /** @var \Core\Observability\SlowQueryLogger|null */
    private mixed $slowQueryLogger = null;

    /**
     * WAVE-07: optional read/write resolver.
     * When present, {@see forRead()} routes eligible reads to the replica connection.
     * When absent (legacy/dev), all queries use the single primary connection.
     */
    private ?\Core\App\ReadWriteConnectionResolver $resolver = null;

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

    /**
     * WAVE-07: Attach the read/write connection resolver.
     *
     * Called from bootstrap.php after both primary and replica config are
     * known. Passing null removes the resolver (dev/test reset).
     */
    public function setReadWriteResolver(?\Core\App\ReadWriteConnectionResolver $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * WAVE-07: Returns the read/write resolver if one is attached.
     * Exposed for health checks and observability only — do not use for routing logic.
     */
    public function getReadWriteResolver(): ?\Core\App\ReadWriteConnectionResolver
    {
        return $this->resolver;
    }

    /**
     * WAVE-07: Returns a read executor that routes to the replica when safe.
     *
     * Safe conditions: resolver is present, replica is configured, sticky-primary
     * mode is NOT active, and the replica connection succeeds.
     *
     * Falls back to primary on any error or when any of the above conditions
     * are not met. This method NEVER throws due to routing failure.
     *
     * Use this ONLY for proven replica-eligible reads (pure display reads with
     * no write-dependency within the same request scope).
     */
    public function forRead(): \Core\App\ReadQueryExecutor
    {
        if ($this->resolver !== null) {
            $result = $this->resolver->replicaConnectionForRead();
            $executor = new \Core\App\ReadQueryExecutor($result['pdo'], $result['target']);
            $executor->setSlowQueryLogger($this->slowQueryLogger);

            return $executor;
        }

        $executor = new \Core\App\ReadQueryExecutor($this->connection(), 'primary');
        $executor->setSlowQueryLogger($this->slowQueryLogger);

        return $executor;
    }

    /**
     * WAVE-07: Force all subsequent reads in this request to use primary.
     *
     * Called automatically by {@see transaction()} and {@see insert()}.
     * Services with post-write read-your-write requirements can call this
     * explicitly after a mutation.
     */
    public function requirePrimary(): void
    {
        if ($this->resolver !== null) {
            $this->resolver->requirePrimary();
        }
    }

    /**
     * WAVE-07: Returns true if sticky-primary mode is active (a write or
     * transaction has been observed in this request scope).
     */
    public function isStickyPrimary(): bool
    {
        return $this->resolver !== null && $this->resolver->isStickyPrimary();
    }

    public function connection(): PDO
    {
        if ($this->pdo === null) {
            if ($this->resolver !== null) {
                $this->pdo = $this->resolver->primaryConnection();
            } else {
                $db = $this->config->get('database');
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $db['host'],
                    $db['port'],
                    $db['database'],
                    $db['charset']
                );
                $this->pdo = new PDO($dsn, $db['username'], $db['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            }
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
        // WAVE-07: Any insert constitutes a write — force primary for subsequent reads.
        $this->requirePrimary();
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
     * WAVE-07: Always uses the primary connection. Entering a transaction triggers
     * sticky-primary mode — all reads in the request scope after this point use
     * primary to guarantee read-your-write correctness.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        // WAVE-07: transactions always on primary; entering one means writes are imminent.
        $this->requirePrimary();
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
