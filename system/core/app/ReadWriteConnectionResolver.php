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
 *   - Lazy config reading: the database config is NOT read at construction time.
 *     It is deferred until the first actual connection use. This means the
 *     resolver is safe to instantiate even when DB_DATABASE is not set — the
 *     error only surfaces when a connection is genuinely needed. This preserves
 *     the same lazy-boot semantics that Database had before WAVE-07.
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

    /** @var array{host: string, port: int, database: string, username: string, password: string, charset: string}|null */
    private ?array $resolvedPrimaryConfig = null;

    /** @var array{host: string, port: int, database: string, username: string, password: string, charset: string}|null|false */
    private mixed $resolvedReplicaConfig = false;  // false = not yet resolved; null = no replica

    /**
     * @param Config $config  Application config. Database section is read lazily on first connection use.
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Returns the primary (write) PDO connection.
     * Always safe — never routes to replica.
     */
    public function primaryConnection(): PDO
    {
        if ($this->primary === null) {
            $this->primary = $this->buildPdo($this->primaryConfig());
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
            $replicaCfg = $this->replicaConfig();
            if ($replicaCfg === null) {
                return ['pdo' => $this->primaryConnection(), 'target' => 'primary'];
            }
            try {
                $this->replica = $this->buildPdo($replicaCfg);
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
        return $this->replicaConfig() !== null
            && !$this->stickyPrimary
            && !$this->replicaConnectFailed;
    }

    /**
     * Returns true if the resolver has replica configuration (regardless of
     * sticky-primary state). Used for health-check and observability reporting.
     */
    public function isReplicaConfigured(): bool
    {
        return $this->replicaConfig() !== null;
    }

    /**
     * Lazily resolve and cache the primary config array.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string, charset: string}
     */
    private function primaryConfig(): array
    {
        if ($this->resolvedPrimaryConfig === null) {
            $db = $this->config->get('database');
            $this->resolvedPrimaryConfig = [
                'host'     => (string) ($db['host'] ?? '127.0.0.1'),
                'port'     => (int) ($db['port'] ?? 3306),
                'database' => (string) ($db['database'] ?? ''),
                'username' => (string) ($db['username'] ?? 'root'),
                'password' => (string) ($db['password'] ?? ''),
                'charset'  => (string) ($db['charset'] ?? 'utf8mb4'),
            ];
        }

        return $this->resolvedPrimaryConfig;
    }

    /**
     * Lazily resolve and cache the replica config array.
     * Returns null if no replica is configured.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string, charset: string}|null
     */
    private function replicaConfig(): ?array
    {
        if ($this->resolvedReplicaConfig === false) {
            $db = $this->config->get('database');
            $replicaHost = trim((string) ($db['replica_host'] ?? ''));
            $routingEnabled = (bool) ($db['read_write_routing_enabled'] ?? false);

            if ($routingEnabled && $replicaHost !== '') {
                $this->resolvedReplicaConfig = [
                    'host'     => $replicaHost,
                    'port'     => (int) ($db['replica_port'] ?? 3306),
                    'database' => (string) ($db['database'] ?? ''),
                    'username' => (string) ($db['username'] ?? 'root'),
                    'password' => (string) ($db['password'] ?? ''),
                    'charset'  => (string) ($db['charset'] ?? 'utf8mb4'),
                ];
            } else {
                $this->resolvedReplicaConfig = null;
            }
        }

        return $this->resolvedReplicaConfig;
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
