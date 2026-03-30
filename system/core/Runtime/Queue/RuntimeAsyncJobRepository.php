<?php

declare(strict_types=1);

namespace Core\Runtime\Queue;

use Core\App\Database;

/**
 * DB-backed durable job queue (FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02).
 *
 * State machine: pending → processing → succeeded | (failed → pending with backoff)* | dead
 */
final class RuntimeAsyncJobRepository
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD = 'dead';

    private const STALE_PROCESSING_SECONDS = 900;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $queue, string $jobType, array $payload, int $maxAttempts = 5): int
    {
        $queue = trim($queue);
        $jobType = trim($jobType);
        if ($queue === '' || $jobType === '') {
            throw new \InvalidArgumentException('Queue and job_type are required.');
        }
        $maxAttempts = max(1, min(50, $maxAttempts));

        return $this->db->insert('runtime_async_jobs', [
            'queue' => $queue,
            'job_type' => $jobType,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
        ]);
    }

    /**
     * Enqueue {@see RuntimeAsyncJobWorkload::JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH} only when no pending job
     * exists with the same {@see RuntimeAsyncJobWorkload::PAYLOAD_KEY_DRAIN_COALESCE} (serialized + FOR UPDATE).
     *
     * @param array<string, mixed> $payload must include matching PAYLOAD_KEY_DRAIN_COALESCE
     * @return bool true when a new row was inserted
     */
    public function enqueueNotificationsOutboundDrainIfAbsent(string $coalesceKey, array $payload, int $maxAttempts = 5): bool
    {
        $coalesceKey = trim($coalesceKey);
        if ($coalesceKey === '') {
            throw new \InvalidArgumentException('coalesceKey is required.');
        }
        $payloadKey = RuntimeAsyncJobWorkload::PAYLOAD_KEY_DRAIN_COALESCE;
        if (($payload[$payloadKey] ?? '') !== $coalesceKey) {
            throw new \InvalidArgumentException('payload[' . $payloadKey . '] must exactly match coalesceKey.');
        }

        return $this->db->transaction(function () use ($coalesceKey, $payload, $maxAttempts, $payloadKey): bool {
            $jsonPathLiteral = '$.' . $payloadKey;
            $existing = $this->db->fetchOne(
                'SELECT id FROM runtime_async_jobs WHERE queue = ? AND job_type = ? AND status = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, \'' . $jsonPathLiteral . '\')) = ? LIMIT 1 FOR UPDATE',
                [
                    RuntimeAsyncJobWorkload::QUEUE_NOTIFICATIONS,
                    RuntimeAsyncJobWorkload::JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH,
                    self::STATUS_PENDING,
                    $coalesceKey,
                ]
            );
            if ($existing !== null) {
                return false;
            }

            $this->enqueue(
                RuntimeAsyncJobWorkload::QUEUE_NOTIFICATIONS,
                RuntimeAsyncJobWorkload::JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH,
                $payload,
                $maxAttempts
            );

            return true;
        });
    }

    /**
     * Reclaims stuck processing rows, then reserves the next pending job (increments attempts).
     *
     * @return array<string, mixed>|null
     */
    public function reserveNext(string $queue): ?array
    {
        return $this->db->transaction(function () use ($queue): ?array {
            $this->reclaimStaleProcessingLocked();

            $row = $this->db->fetchOne(
                'SELECT id FROM runtime_async_jobs WHERE queue = ? AND status = ? AND available_at <= NOW(3) ORDER BY id ASC LIMIT 1 FOR UPDATE',
                [$queue, self::STATUS_PENDING]
            );
            if ($row === null) {
                return null;
            }
            $id = (int) $row['id'];
            $this->db->query(
                'UPDATE runtime_async_jobs SET status = ?, reserved_at = NOW(3), attempts = attempts + 1, updated_at = NOW(3) WHERE id = ?',
                [self::STATUS_PROCESSING, $id]
            );
            $full = $this->db->fetchOne('SELECT * FROM runtime_async_jobs WHERE id = ?', [$id]);

            return $full !== null ? $full : null;
        });
    }

    public function markSucceeded(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }
        $this->db->query(
            'UPDATE runtime_async_jobs SET status = ?, updated_at = NOW(3) WHERE id = ?',
            [self::STATUS_SUCCEEDED, $jobId]
        );
    }

    /**
     * After a handler failure: either re-queue with backoff or mark dead when attempts exhausted.
     */
    public function markFailedRetryOrDead(int $jobId, string $errorMessage, int $baseBackoffSeconds = 60): void
    {
        if ($jobId <= 0) {
            return;
        }
        $row = $this->db->fetchOne('SELECT attempts, max_attempts FROM runtime_async_jobs WHERE id = ?', [$jobId]);
        if ($row === null) {
            return;
        }
        $attempts = (int) $row['attempts'];
        $max = (int) $row['max_attempts'];
        $err = mb_substr(trim($errorMessage), 0, 3900);
        $baseBackoffSeconds = max(5, min(86400, $baseBackoffSeconds));

        if ($attempts >= $max) {
            $this->db->query(
                'UPDATE runtime_async_jobs SET status = ?, last_error = ?, updated_at = NOW(3) WHERE id = ?',
                [self::STATUS_DEAD, $err, $jobId]
            );

            return;
        }
        $delay = $baseBackoffSeconds * $attempts;
        $delay = min(86400, max($baseBackoffSeconds, $delay));
        $this->db->query(
            'UPDATE runtime_async_jobs SET status = ?, reserved_at = NULL, last_error = ?, available_at = DATE_ADD(NOW(3), INTERVAL ? SECOND), updated_at = NOW(3) WHERE id = ?',
            [self::STATUS_PENDING, $err, $delay, $jobId]
        );
    }

    private function reclaimStaleProcessingLocked(): void
    {
        $sec = (int) self::STALE_PROCESSING_SECONDS;
        $this->db->query(
            "UPDATE runtime_async_jobs SET status = ?, reserved_at = NULL, last_error = CONCAT(COALESCE(last_error, ''), ' | stale_reclaim'), updated_at = NOW(3) WHERE status = ? AND reserved_at IS NOT NULL AND reserved_at < DATE_SUB(NOW(3), INTERVAL {$sec} SECOND)",
            [self::STATUS_PENDING, self::STATUS_PROCESSING]
        );
    }
}
