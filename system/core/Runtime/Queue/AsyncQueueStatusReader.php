<?php

declare(strict_types=1);

namespace Core\Runtime\Queue;

use Core\App\Database;

/**
 * Read-only operator visibility into the unified async queue control-plane (PLT-Q-01).
 *
 * Provides canonical status surfaces for queued, running, stuck, failed, dead,
 * retrying, and recently completed jobs — sufficient for CLI diagnostics and
 * operator health checks without requiring a full admin UI.
 *
 * All methods are read-only and safe to call at any time.
 */
final class AsyncQueueStatusReader
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Job counts grouped by queue and status.
     * Returns array keyed by queue, each value an array keyed by status.
     *
     * @return array<string, array<string, int>>
     */
    public function getQueueDepthByStatus(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT queue, status, COUNT(*) AS cnt
             FROM runtime_async_jobs
             GROUP BY queue, status
             ORDER BY queue ASC, status ASC'
        );
        $result = [];
        foreach ($rows as $row) {
            $q = (string) ($row['queue'] ?? '');
            $s = (string) ($row['status'] ?? '');
            $result[$q][$s] = (int) ($row['cnt'] ?? 0);
        }

        return $result;
    }

    /**
     * Jobs currently in processing state that have been reserved beyond the stale threshold.
     * These are candidates for stale-reclaim on the next reserveNext() call.
     *
     * @return list<array<string, mixed>>
     */
    public function getStuckJobs(int $staleSeconds = 900): array
    {
        $sec = max(60, min(86400, $staleSeconds));

        return $this->db->fetchAll(
            'SELECT id, queue, job_type, attempts, max_attempts, reserved_at,
                    LEFT(last_error, 500) AS last_error_preview,
                    available_at, created_at, updated_at
             FROM runtime_async_jobs
             WHERE status = ?
               AND reserved_at IS NOT NULL
               AND reserved_at < DATE_SUB(NOW(3), INTERVAL ' . $sec . ' SECOND)
             ORDER BY reserved_at ASC
             LIMIT 50',
            [RuntimeAsyncJobRepository::STATUS_PROCESSING]
        );
    }

    /**
     * Jobs in the dead-letter state (exhausted all retry attempts).
     *
     * @return list<array<string, mixed>>
     */
    public function getDeadJobs(int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->fetchAll(
            'SELECT id, queue, job_type, attempts, max_attempts,
                    LEFT(last_error, 500) AS last_error_preview,
                    available_at, created_at, updated_at
             FROM runtime_async_jobs
             WHERE status = ?
             ORDER BY updated_at DESC
             LIMIT ' . $limit,
            [RuntimeAsyncJobRepository::STATUS_DEAD]
        );
    }

    /**
     * Jobs in pending state that have already been attempted at least once (retry_wait phase).
     *
     * @return list<array<string, mixed>>
     */
    public function getRetryingJobs(int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->fetchAll(
            'SELECT id, queue, job_type, attempts, max_attempts, available_at,
                    LEFT(last_error, 500) AS last_error_preview,
                    created_at, updated_at
             FROM runtime_async_jobs
             WHERE status = ?
               AND attempts > 0
               AND available_at > NOW(3)
             ORDER BY available_at ASC
             LIMIT ' . $limit,
            [RuntimeAsyncJobRepository::STATUS_PENDING]
        );
    }

    /**
     * Most recently succeeded jobs.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecentCompletions(int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->fetchAll(
            'SELECT id, queue, job_type, attempts, created_at, updated_at
             FROM runtime_async_jobs
             WHERE status = ?
             ORDER BY updated_at DESC
             LIMIT ' . $limit,
            [RuntimeAsyncJobRepository::STATUS_SUCCEEDED]
        );
    }

    /**
     * Canonical summary for operator health checks and CLI diagnostics.
     *
     * @return array{
     *   queued: int,
     *   processing: int,
     *   stuck: int,
     *   retrying: int,
     *   dead: int,
     *   succeeded_recent: int,
     *   by_queue: array<string, array<string, int>>,
     * }
     */
    public function getSummary(int $staleSeconds = 900): array
    {
        $depth = $this->getQueueDepthByStatus();
        $queued = 0;
        $processing = 0;
        $dead = 0;
        $succeeded = 0;
        foreach ($depth as $statusMap) {
            $queued += (int) ($statusMap[RuntimeAsyncJobRepository::STATUS_PENDING] ?? 0);
            $processing += (int) ($statusMap[RuntimeAsyncJobRepository::STATUS_PROCESSING] ?? 0);
            $dead += (int) ($statusMap[RuntimeAsyncJobRepository::STATUS_DEAD] ?? 0);
            $succeeded += (int) ($statusMap[RuntimeAsyncJobRepository::STATUS_SUCCEEDED] ?? 0);
        }

        return [
            'queued' => $queued,
            'processing' => $processing,
            'stuck' => count($this->getStuckJobs($staleSeconds)),
            'retrying' => count($this->getRetryingJobs()),
            'dead' => $dead,
            'succeeded_recent' => $succeeded,
            'by_queue' => $depth,
        ];
    }
}
