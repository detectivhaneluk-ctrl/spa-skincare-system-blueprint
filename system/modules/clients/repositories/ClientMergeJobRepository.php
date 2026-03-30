<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;
use Modules\Clients\Support\ClientMergeJobStatuses;

final class ClientMergeJobRepository
{
    private const TABLE = 'client_merge_jobs';

    public function __construct(
        private Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        return $this->db->insert(self::TABLE, $row);
    }

    /**
     * Tenant-safe load: row must belong to {@code $organizationId}.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdForOrganization(int $id, int $organizationId): ?array
    {
        if ($id <= 0 || $organizationId <= 0) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = ? AND organization_id = ? LIMIT 1',
            [$id, $organizationId]
        );

        return $row ?: null;
    }

    /**
     * Worker / cron only: id-keyed read with **no** organization predicate (claim + reconciliation entry).
     *
     * @return array<string, mixed>|null
     */
    public function findByIdForWorker(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1',
            [$id]
        );

        return $row ?: null;
    }

    /**
     * Oldest running job that started before the stale threshold and never finished. Caller must run inside an open transaction for {@code FOR UPDATE}.
     *
     * @return array<string, mixed>|null
     */
    public function findOldestStaleRunningForUpdate(int $staleMinutes): ?array
    {
        $m = max(1, min(24 * 60, $staleMinutes));
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . '
             WHERE status = ?
               AND finished_at IS NULL
               AND started_at IS NOT NULL
               AND started_at < DATE_SUB(NOW(), INTERVAL ' . $m . ' MINUTE)
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE',
            [ClientMergeJobStatuses::RUNNING]
        );

        return $row ?: null;
    }

    public function existsQueuedOrRunningForPair(int $organizationId, int $primaryClientId, int $secondaryClientId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM ' . self::TABLE . '
             WHERE organization_id = ?
               AND primary_client_id = ?
               AND secondary_client_id = ?
               AND status IN (?, ?)
             LIMIT 1',
            [
                $organizationId,
                $primaryClientId,
                $secondaryClientId,
                ClientMergeJobStatuses::QUEUED,
                ClientMergeJobStatuses::RUNNING,
            ]
        );

        return isset($row['ok']) && (int) $row['ok'] === 1;
    }

    /**
     * Tenant-safe patch: only rows with matching {@code organization_id} are updated.
     *
     * @param array<string, mixed> $patch
     */
    public function updateByIdForOrganization(int $id, int $organizationId, array $patch): void
    {
        if ($patch === [] || $id <= 0 || $organizationId <= 0) {
            return;
        }
        $sets = [];
        $vals = [];
        foreach ($patch as $col => $val) {
            if (!is_string($col) || $col === '') {
                continue;
            }
            $sets[] = $col . ' = ?';
            $vals[] = $val;
        }
        if ($sets === []) {
            return;
        }
        $vals[] = $id;
        $vals[] = $organizationId;
        $this->db->query(
            'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ? AND organization_id = ?',
            $vals
        );
    }

    /**
     * Worker / recovery only: {@code WHERE id = ?} with no organization predicate (e.g. invalid-row cleanup when org id unknown).
     *
     * @param array<string, mixed> $patch
     */
    public function updateByIdForWorker(int $id, array $patch): void
    {
        if ($patch === [] || $id <= 0) {
            return;
        }
        $sets = [];
        $vals = [];
        foreach ($patch as $col => $val) {
            if (!is_string($col) || $col === '') {
                continue;
            }
            $sets[] = $col . ' = ?';
            $vals[] = $val;
        }
        if ($sets === []) {
            return;
        }
        $vals[] = $id;
        $this->db->query(
            'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $vals
        );
    }
}
