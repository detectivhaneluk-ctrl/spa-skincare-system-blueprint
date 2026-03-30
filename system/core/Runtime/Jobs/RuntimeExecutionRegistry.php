<?php

declare(strict_types=1);

namespace Core\Runtime\Jobs;

use Core\App\Config;
use Core\App\Database;
use PDO;

/**
 * DB-backed execution ledger for schedulers and workers (FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01).
 *
 * Modes:
 * - **Exclusive** ({@see beginExclusiveRun}): at most one non-stale active holder; concurrent second run throws {@see RuntimeExecutionConflictException}.
 * - **Parallel-safe** ({@see recordParallelBatchStart} / {@see completeParallelBatch}): records timestamps for overlapping-safe batch workers (e.g. outbound dispatch).
 * - **Worker liveness** ({@see recordWorkerLoopHeartbeat}): long-running Node worker; stale heartbeats surface absent worker while backlog exists.
 */
final class RuntimeExecutionRegistry
{
    public function __construct(
        private Database $database,
        private Config $config
    ) {
    }

    public function staleMinutesFor(string $executionKey): int
    {
        $map = (array) $this->config->get('runtime_jobs.stale_minutes_by_key', []);
        if (str_starts_with($executionKey, RuntimeExecutionKeys::PHP_MARKETING_AUTOMATIONS_PREFIX)) {
            $k = 'php:marketing_automations';
        } else {
            $k = $executionKey;
        }
        $m = isset($map[$k]) ? (int) $map[$k] : 0;
        if ($m > 0) {
            return $m;
        }

        return max(1, (int) $this->config->get('runtime_jobs.default_stale_minutes', 120));
    }

    /**
     * @throws RuntimeExecutionConflictException
     */
    public function beginExclusiveRun(string $executionKey, ?string $activeMeta = null): void
    {
        $pdo = $this->database->connection();
        $staleMin = $this->staleMinutesFor($executionKey);
        $pdo->beginTransaction();
        try {
            $row = $this->lockRow($pdo, $executionKey);
            if ($row === null) {
                $this->insertExclusiveRow($pdo, $executionKey, $activeMeta);

                $pdo->commit();

                return;
            }
            $activeSince = $row['active_started_at'] ?? null;
            if ($activeSince === null || $activeSince === '') {
                $this->activateExclusive($pdo, $executionKey, $activeMeta);
                $pdo->commit();

                return;
            }
            $minutesSinceLife = $this->minutesSinceLastLife($row);
            if ($minutesSinceLife < $staleMin) {
                $pdo->rollBack();
                throw new RuntimeExecutionConflictException(
                    'Exclusive run already active for ' . $executionKey . ' (not stale; last_life_minutes_ago=' . $minutesSinceLife . ').'
                );
            }
            $note = $this->truncateSummary(
                'stale_exclusive_run_cleared previous_active_since=' . (string) $activeSince
                . ' last_heartbeat=' . (string) ($row['active_heartbeat_at'] ?? '')
            );
            $this->query(
                $pdo,
                'UPDATE runtime_execution_registry SET
                    last_error_summary = ?,
                    active_started_at = NOW(3),
                    active_heartbeat_at = NOW(3),
                    last_started_at = NOW(3),
                    active_meta = ?,
                    updated_at = NOW(3)
                 WHERE execution_key = ?',
                [$note, $activeMeta, $executionKey]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function heartbeatExclusive(string $executionKey): void
    {
        $pdo = $this->database->connection();
        $this->query(
            $pdo,
            'UPDATE runtime_execution_registry SET active_heartbeat_at = NOW(3), updated_at = NOW(3) WHERE execution_key = ?',
            [$executionKey]
        );
    }

    public function completeExclusiveSuccess(string $executionKey): void
    {
        $pdo = $this->database->connection();
        $this->query(
            $pdo,
            'UPDATE runtime_execution_registry SET
                last_finished_at = NOW(3),
                last_success_at = NOW(3),
                active_started_at = NULL,
                active_heartbeat_at = NULL,
                active_meta = NULL,
                last_error_summary = NULL,
                updated_at = NOW(3)
             WHERE execution_key = ?',
            [$executionKey]
        );
    }

    public function completeExclusiveFailure(string $executionKey, string $errorSummary): void
    {
        $pdo = $this->database->connection();
        $summary = $this->truncateSummary($errorSummary);
        $this->query(
            $pdo,
            'UPDATE runtime_execution_registry SET
                last_finished_at = NOW(3),
                last_failure_at = NOW(3),
                last_error_summary = ?,
                active_started_at = NULL,
                active_heartbeat_at = NULL,
                active_meta = NULL,
                updated_at = NOW(3)
             WHERE execution_key = ?',
            [$summary, $executionKey]
        );
    }

    /** Parallel-safe: overlapping dispatchers allowed; does not use active_* mutex columns. */
    public function recordParallelBatchStart(string $executionKey): void
    {
        $pdo = $this->database->connection();
        $pdo->beginTransaction();
        try {
            $row = $this->lockRow($pdo, $executionKey);
            if ($row === null) {
                $this->query(
                    $pdo,
                    'INSERT INTO runtime_execution_registry (execution_key, last_started_at, last_heartbeat_at, updated_at)
                     VALUES (?, NOW(3), NOW(3), NOW(3))',
                    [$executionKey]
                );
            } else {
                $this->query(
                    $pdo,
                    'UPDATE runtime_execution_registry SET
                        last_started_at = NOW(3),
                        last_heartbeat_at = NOW(3),
                        updated_at = NOW(3)
                     WHERE execution_key = ?',
                    [$executionKey]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function completeParallelBatchSuccess(string $executionKey): void
    {
        $pdo = $this->database->connection();
        $this->query(
            $pdo,
            'UPDATE runtime_execution_registry SET
                last_finished_at = NOW(3),
                last_success_at = NOW(3),
                last_error_summary = NULL,
                last_heartbeat_at = NOW(3),
                updated_at = NOW(3)
             WHERE execution_key = ?',
            [$executionKey]
        );
    }

    public function completeParallelBatchFailure(string $executionKey, string $errorSummary): void
    {
        $pdo = $this->database->connection();
        $summary = $this->truncateSummary($errorSummary);
        $this->query(
            $pdo,
            'UPDATE runtime_execution_registry SET
                last_finished_at = NOW(3),
                last_failure_at = NOW(3),
                last_error_summary = ?,
                last_heartbeat_at = NOW(3),
                updated_at = NOW(3)
             WHERE execution_key = ?',
            [$summary, $executionKey]
        );
    }

    public function recordWorkerLoopHeartbeat(string $executionKey, ?string $meta = null): void
    {
        $pdo = $this->database->connection();
        $pdo->beginTransaction();
        try {
            $row = $this->lockRow($pdo, $executionKey);
            if ($row === null) {
                $this->query(
                    $pdo,
                    'INSERT INTO runtime_execution_registry (execution_key, last_started_at, last_heartbeat_at, active_heartbeat_at, active_started_at, active_meta, updated_at)
                     VALUES (?, NOW(3), NOW(3), NOW(3), NOW(3), ?, NOW(3))',
                    [$executionKey, $meta]
                );
            } else {
                $this->query(
                    $pdo,
                    'UPDATE runtime_execution_registry SET
                        last_heartbeat_at = NOW(3),
                        active_heartbeat_at = NOW(3),
                        active_started_at = COALESCE(active_started_at, NOW(3)),
                        active_meta = COALESCE(?, active_meta),
                        updated_at = NOW(3)
                     WHERE execution_key = ?',
                    [$meta, $executionKey]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function recordWorkerShutdown(string $executionKey, bool $success, ?string $errorSummary = null): void
    {
        $pdo = $this->database->connection();
        if ($success) {
            $this->query(
                $pdo,
                'UPDATE runtime_execution_registry SET
                    last_finished_at = NOW(3),
                    last_success_at = NOW(3),
                    active_started_at = NULL,
                    active_heartbeat_at = NULL,
                    last_error_summary = NULL,
                    updated_at = NOW(3)
                 WHERE execution_key = ?',
                [$executionKey]
            );
        } else {
            $s = $this->truncateSummary((string) $errorSummary);
            $this->query(
                $pdo,
                'UPDATE runtime_execution_registry SET
                    last_finished_at = NOW(3),
                    last_failure_at = NOW(3),
                    last_error_summary = ?,
                    active_started_at = NULL,
                    active_heartbeat_at = NULL,
                    updated_at = NOW(3)
                 WHERE execution_key = ?',
                [$s, $executionKey]
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllForReadOnlyReport(): array
    {
        return $this->database->fetchAll(
            'SELECT execution_key, last_started_at, last_finished_at, last_success_at, last_failure_at,
                    LEFT(last_error_summary, 500) AS last_error_summary_preview,
                    last_heartbeat_at, active_started_at, active_heartbeat_at, active_meta, updated_at
             FROM runtime_execution_registry ORDER BY execution_key ASC'
        );
    }

    private function lockRow(PDO $pdo, string $executionKey): ?array
    {
        $st = $pdo->prepare('SELECT execution_key, active_started_at, active_heartbeat_at, last_heartbeat_at FROM runtime_execution_registry WHERE execution_key = ? FOR UPDATE');
        $st->execute([$executionKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function insertExclusiveRow(PDO $pdo, string $executionKey, ?string $activeMeta): void
    {
        $this->query(
            $pdo,
            'INSERT INTO runtime_execution_registry (execution_key, last_started_at, active_started_at, active_heartbeat_at, active_meta, updated_at)
             VALUES (?, NOW(3), NOW(3), NOW(3), ?, NOW(3))',
            [$executionKey, $activeMeta]
        );
    }

    private function activateExclusive(PDO $pdo, string $executionKey, ?string $activeMeta): void
    {
        $this->query(
            $pdo,
            'UPDATE runtime_execution_registry SET
                last_started_at = NOW(3),
                active_started_at = NOW(3),
                active_heartbeat_at = NOW(3),
                active_meta = ?,
                updated_at = NOW(3)
             WHERE execution_key = ?',
            [$activeMeta, $executionKey]
        );
    }

    /** @param array<string, mixed> $row */
    private function minutesSinceLastLife(array $row): int
    {
        $life = $row['active_heartbeat_at'] ?? null;
        if ($life === null || $life === '') {
            $life = $row['active_started_at'] ?? null;
        }
        if ($life === null || $life === '') {
            return 99999;
        }
        $ts = strtotime((string) $life);

        return $ts === false ? 99999 : max(0, (int) floor((time() - $ts) / 60));
    }

    private function truncateSummary(string $s): string
    {
        $s = trim($s);
        if (strlen($s) <= 2000) {
            return $s;
        }

        return substr($s, 0, 1997) . '...';
    }

    private function query(PDO $pdo, string $sql, array $params): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}
