<?php

declare(strict_types=1);

namespace Core\App;

use PDO;

/**
 * WAVE-07: Read/write connection resolver.
 *
 * Manages two PDO connections — primary (write) and replica (read) — and
 * enforces safe routing semantics:
 *
 *   - When replica is NOT configured: both primary and replica return the same
 *     primary PDO. Safe for local dev and environments without a read replica.
 *
 *   - When replica IS configured: primary returns the primary PDO, replica
 *     returns the replica PDO. Routing is explicit and inspectable.
 *
 *   - Sticky-primary mode: once a write or transaction begins, all subsequent
 *     reads in this request scope must use primary. Call {@see requirePrimary()}
 *     to enter sticky mode. It is never reverted within a request lifecycle.
 *
 *   - Fail-safe: if the replica connection throws on connect, canUseReplica()
 *     returns false and callers fall back to primary. The error is logged but
 *     never bubbles up as a fatal.
 *
 * This resolver is a singleton registered in bootstrap.php. The Database class
 * holds a reference to it and exposes {@see Database::forRead()} and
 * {@see Database::requirePrimary()}.
 */
final class ReadWriteConnectionResolver
{
    private ?PDO $primary = null;
    private ?PDO $replica = null;
    private bool $stickyPrimary = false;
    private bool $replicaConnectFailed = false;

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string, charset: string} $primaryConfig
     * @param array{host: string, port: int, database: string, username: string, password: string, charset: string}|null $replicaConfig null = no split
     */
    public function __construct(
        private readonly array $primaryConfig,
        private readonly ?array $replicaConfig
    ) {
    }

    /**
     * Returns the primary (write) PDO connection.
     * Always safe — never routes to replica.
     */
    public function primaryConnection(): PDO
    {
        if ($this->primary === null) {
            $this->primary = $this->buildPdo($this->primaryConfig);
        }

        return $this->primary;
    }

    /**
     * Returns the replica PDO if: replica is configured, connection succeeded,
     * and sticky-primary mode is NOT active.
     *
     * Falls back to primary on any error or when sticky-primary is set.
     *
     * @return array{pdo: PDO, target: string}  target is 'replica' or 'primary_fallback'
     */
    public function replicaConnectionForRead(): array
    {
        if (!$this->canUseReplica()) {
            return ['pdo' => $this->primaryConnection(), 'target' => 'primary'];
        }

        if ($this->replicaConnectFailed) {
            return ['pdo' => $this->primaryConnection(), 'target' => 'primary_fallback_replica_unavailable'];
        }

        if ($this->replica === null) {
            try {
                $this->replica = $this->buildPdo($this->replicaConfig);
            } catch (\Throwable $e) {
                $this->replicaConnectFailed = true;
                if (function_exists('slog')) {
                    \slog('warning', 'db_routing', 'replica_connect_failed', [
                        'error' => substr($e->getMessage(), 0, 240),
                    ]);
                }

                return ['pdo' => $this->primaryConnection(), 'target' => 'primary_fallback_replica_connect_error'];
            }
        }

        return ['pdo' => $this->replica, 'target' => 'replica'];
    }

    /**
     * Force all subsequent reads in this request to use primary.
     *
     * Called automatically when a transaction starts or an INSERT/UPDATE/DELETE
     * runs. Also callable explicitly by services that know they need read-your-write
     * semantics after a mutation.
     *
     * Once set, sticky-primary is NEVER cleared within a request lifecycle.
     */
    public function requirePrimary(): void
    {
        $this->stickyPrimary = true;
    }

    /**
     * Returns true if a write or transaction has been observed in this request,
     * forcing all reads to primary.
     */
    public function isStickyPrimary(): bool
    {
        return $this->stickyPrimary;
    }

    /**
     * Returns true if a replica connection is configured, the connection has not
     * permanently failed, and sticky-primary is not active.
     */
    public function canUseReplica(): bool
    {
        return $this->replicaConfig !== null
            && !$this->stickyPrimary
            && !$this->replicaConnectFailed;
    }

    /**
     * Returns true if the resolver has replica configuration (regardless of
     * sticky-primary state). Used for health-check and observability reporting.
     */
    public function isReplicaConfigured(): bool
    {
        return $this->replicaConfig !== null;
    }

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string, charset: string} $cfg
     */
    private function buildPdo(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['database'],
            $cfg['charset']
        );

        return new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
