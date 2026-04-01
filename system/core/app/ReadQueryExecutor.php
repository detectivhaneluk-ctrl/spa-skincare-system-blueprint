<?php

declare(strict_types=1);

namespace Core\App;

use PDO;

/**
 * WAVE-07: Read query executor — wraps a PDO connection for explicit replica-eligible reads.
 *
 * Returned by {@see Database::forRead()}. Exposes the same fetch API as Database
 * but is tied to a specific PDO that may be a replica or primary (depending on
 * routing state at call-time). The routing target is queryable for observability.
 *
 * Critically, this class does NOT expose insert(), exec(), or transaction() — it
 * cannot be used for writes. That is the guardrail boundary: code that holds a
 * ReadQueryExecutor can only read.
 *
 * Slow-query logging is forwarded to the same SlowQueryLogger as the primary
 * connection when one is attached.
 */
final class ReadQueryExecutor
{
    /** @var \Core\Observability\SlowQueryLogger|null */
    private mixed $slowQueryLogger = null;

    /**
     * @param string $routingTarget 'primary' | 'replica' | 'primary_fallback*'
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $routingTarget
    ) {
    }

    public function setSlowQueryLogger(?\Core\Observability\SlowQueryLogger $logger): void
    {
        $this->slowQueryLogger = $logger;
    }

    /**
     * Returns the routing target for this executor: 'primary', 'replica', or
     * a 'primary_fallback_*' variant. Useful for logging and observability.
     */
    public function routingTarget(): string
    {
        return $this->routingTarget;
    }

    /**
     * Returns true if this executor is using a replica connection.
     */
    public function isReplica(): bool
    {
        return $this->routingTarget === 'replica';
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->executeQuery($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->executeQuery($sql, $params)->fetch() ?: null;
    }

    private function executeQuery(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? (string) $key : ':' . (string) $key);
            $type  = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
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
}
