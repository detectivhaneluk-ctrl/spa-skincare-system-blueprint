<?php

declare(strict_types=1);

namespace Modules\Notifications\Repositories;

use Core\App\Database;
use Core\Runtime\Queue\RuntimeAsyncJobRepository;
use Core\Runtime\Queue\RuntimeAsyncJobWorkload;
use Modules\Notifications\Services\OutboundChannelPolicy;

final class OutboundNotificationMessageRepository
{
    public function __construct(
        private Database $db,
        private RuntimeAsyncJobRepository $runtimeAsyncJobs,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     *
     * Pairs domain insert with a coalesced runtime {@see RuntimeAsyncJobWorkload::JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH} enqueue (one transaction).
     */
    public function insert(array $row): int
    {
        OutboundChannelPolicy::assertEnqueueAllowed((string) ($row['channel'] ?? ''));

        return $this->db->transaction(function () use ($row): int {
            $id = $this->db->insert('outbound_notification_messages', $row);
            $branchRaw = $row['branch_id'] ?? null;
            $branchId = null;
            if ($branchRaw !== null && $branchRaw !== '' && (int) $branchRaw > 0) {
                $branchId = (int) $branchRaw;
            }
            $coalesceKey = RuntimeAsyncJobWorkload::notificationOutboundDrainCoalesceKey($branchId);
            $this->runtimeAsyncJobs->enqueueNotificationsOutboundDrainIfAbsent(
                $coalesceKey,
                [
                    RuntimeAsyncJobWorkload::PAYLOAD_KEY_DRAIN_COALESCE => $coalesceKey,
                    'outbound_notification_message_id' => $id,
                    'schema' => RuntimeAsyncJobWorkload::PAYLOAD_SCHEMA,
                ],
                5
            );

            return $id;
        });
    }

    /**
     * Queue-worker-internal id-only read. NOT for tenant HTTP paths.
     *
     * ROOT-01 note: this method carries no intrinsic org/branch predicate.
     * Contract: callers must hold an authoritative claim on the row (e.g. queue worker that
     * claimed the row via {@see claimPendingBatchForDispatch}) — not for arbitrary id lookups.
     * There are zero HTTP callers of this method in the codebase; do not add any without
     * first adding an org-scope predicate or using a scoped variant.
     *
     * @internal queue-worker only
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM outbound_notification_messages WHERE id = ?', [$id]);
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM outbound_notification_messages WHERE idempotency_key = ? LIMIT 1',
            [$key]
        ) ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPending(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        return $this->db->fetchAll(
            'SELECT * FROM outbound_notification_messages
             WHERE status = \'pending\'
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
             ORDER BY id ASC
             LIMIT ' . $limit
        );
    }

    /**
     * Atomically claims pending rows for dispatch (pending → processing). Uses FOR UPDATE SKIP LOCKED (MySQL 8+ / MariaDB 10.6+).
     *
     * @return list<array<string, mixed>>
     */
    public function claimPendingBatchForDispatch(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $pdo = $this->db->connection();
        $pdo->beginTransaction();
        try {
            $rows = $this->db->fetchAll(
                'SELECT * FROM outbound_notification_messages
                 WHERE status = \'pending\'
                   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                 ORDER BY id ASC
                 LIMIT ' . $limit . '
                 FOR UPDATE SKIP LOCKED'
            );
            if ($rows === []) {
                $pdo->commit();

                return [];
            }
            $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
            if ($ids === []) {
                $pdo->commit();

                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $now = date('Y-m-d H:i:s');
            $this->db->query(
                'UPDATE outbound_notification_messages SET status = \'processing\', claimed_at = ? WHERE id IN (' . $placeholders . ')',
                array_merge([$now], $ids)
            );
            $pdo->commit();
            foreach ($rows as &$r) {
                $r['status'] = 'processing';
                $r['claimed_at'] = $now;
            }
            unset($r);

            return $rows;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function reclaimStaleProcessingClaims(int $olderThanMinutes): int
    {
        $olderThanMinutes = max(1, min(10080, $olderThanMinutes));
        $stmt = $this->db->query(
            'UPDATE outbound_notification_messages
             SET status = \'pending\', claimed_at = NULL, skip_reason = NULL,
                 error_summary = \'stale_processing_reclaimed\', failed_at = NULL
             WHERE status = \'processing\'
               AND claimed_at IS NOT NULL
               AND claimed_at < DATE_SUB(NOW(), INTERVAL ' . (int) $olderThanMinutes . ' MINUTE)'
        );

        return (int) $stmt->rowCount();
    }

    public function finishClaimedSuccess(int $id, string $terminalStatus, string $sentAtMysql): void
    {
        $this->db->query(
            'UPDATE outbound_notification_messages
             SET status = ?, sent_at = ?, claimed_at = NULL, failed_at = NULL, error_summary = NULL, skip_reason = NULL
             WHERE id = ? AND status = \'processing\'',
            [$terminalStatus, $sentAtMysql, $id]
        );
    }

    public function finishClaimedFailure(int $id, string $errorSummary, string $failedAtMysql): void
    {
        $err = substr($errorSummary, 0, 500);
        $this->db->query(
            'UPDATE outbound_notification_messages
             SET status = \'failed\', error_summary = ?, failed_at = ?, claimed_at = NULL
             WHERE id = ? AND status = \'processing\'',
            [$err, $failedAtMysql, $id]
        );
    }

    public function finishClaimedSkipped(int $id, string $skipReason): void
    {
        $r = substr($skipReason, 0, 500);
        $this->db->query(
            'UPDATE outbound_notification_messages
             SET status = \'skipped\', skip_reason = ?, claimed_at = NULL, failed_at = NULL
             WHERE id = ? AND status = \'processing\'',
            [$r, $id]
        );
    }

    public function scheduleClaimedRetry(int $id, string $scheduledAtMysql, string $errorSummary): void
    {
        $err = substr($errorSummary, 0, 500);
        $this->db->query(
            'UPDATE outbound_notification_messages
             SET status = \'pending\', scheduled_at = ?, error_summary = ?, claimed_at = NULL, failed_at = NULL
             WHERE id = ? AND status = \'processing\'',
            [$scheduledAtMysql, $err, $id]
        );
    }

    /**
     * Mark still-queued messages skipped so the worker never sends after lifecycle invalidation.
     * Only rows with status `pending` are updated (not `processing`, to avoid racing an active worker claim).
     *
     * @return int rows updated
     */
    public function skipPendingByEntityTypeAndEventKey(string $entityType, int $entityId, string $eventKey, string $skipReason): int
    {
        $entityType = trim($entityType);
        $eventKey = trim($eventKey);
        if ($entityType === '' || $entityId <= 0 || $eventKey === '') {
            return 0;
        }
        $reason = substr($skipReason, 0, 500);
        $stmt = $this->db->query(
            "UPDATE outbound_notification_messages
             SET status = 'skipped', skip_reason = ?, claimed_at = NULL, failed_at = NULL, error_summary = NULL
             WHERE entity_type = ?
               AND entity_id = ?
               AND event_key = ?
               AND status = 'pending'",
            [$reason, $entityType, $entityId, $eventKey]
        );

        return $stmt->rowCount();
    }

    public function updateStatus(
        int $id,
        string $status,
        ?string $skipReason = null,
        ?string $errorSummary = null,
        ?string $sentAt = null,
        ?string $failedAt = null
    ): void {
        $sets = ['status = ?'];
        $params = [$status];
        if ($skipReason !== null) {
            $sets[] = 'skip_reason = ?';
            $params[] = $skipReason;
        }
        if ($errorSummary !== null) {
            $sets[] = 'error_summary = ?';
            $params[] = $errorSummary;
        }
        if ($sentAt !== null) {
            $sets[] = 'sent_at = ?';
            $params[] = $sentAt;
        }
        if ($failedAt !== null) {
            $sets[] = 'failed_at = ?';
            $params[] = $failedAt;
        }
        $params[] = $id;
        $this->db->query(
            'UPDATE outbound_notification_messages SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }
}
