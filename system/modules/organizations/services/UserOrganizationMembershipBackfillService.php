<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\App\Database;
use Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository;

/**
 * Deterministic branch→org membership backfill (F-48). Mutates only via {@see run} INSERTs.
 */
final class UserOrganizationMembershipBackfillService
{
    public function __construct(
        private Database $db,
        private UserOrganizationMembershipReadRepository $membershipRepository,
    ) {
    }

    /**
     * @return array{
     *   scanned: int,
     *   inserted: int,
     *   skipped_existing: int,
     *   skipped_ambiguous: int,
     *   skipped_no_branch: int,
     *   skipped_missing_branch_org: int,
     *   dry_run: bool
     * }
     */
    public function run(bool $dryRun = false): array
    {
        $scanned = 0;
        $inserted = 0;
        $skippedExisting = 0;
        $skippedAmbiguous = 0;
        $skippedNoBranch = 0;
        $skippedMissingBranchOrg = 0;

        if (!$this->membershipRepository->isMembershipTablePresent()) {
            return [
                'scanned' => 0,
                'inserted' => 0,
                'skipped_existing' => 0,
                'skipped_ambiguous' => 0,
                'skipped_no_branch' => 0,
                'skipped_missing_branch_org' => 0,
                'dry_run' => $dryRun,
            ];
        }

        $users = $this->db->fetchAll(
            'SELECT id, branch_id FROM users WHERE deleted_at IS NULL ORDER BY id ASC'
        );

        foreach ($users as $row) {
            ++$scanned;
            $userId = (int) ($row['id'] ?? 0);
            $branchId = isset($row['branch_id']) && $row['branch_id'] !== null ? (int) $row['branch_id'] : null;

            if ($branchId === null || $branchId <= 0) {
                ++$skippedNoBranch;

                continue;
            }

            $resolved = $this->resolveBranchOrganizationId($branchId);
            if ($resolved === null) {
                ++$skippedMissingBranchOrg;

                continue;
            }
            $organizationId = $resolved;

            if ($this->membershipRowExists($userId, $organizationId)) {
                ++$skippedExisting;

                continue;
            }

            $activeIds = $this->listActiveOrganizationIdsForUser($userId);
            $activeCount = count($activeIds);

            if ($activeCount > 1) {
                ++$skippedAmbiguous;

                continue;
            }

            if ($activeCount === 1 && $activeIds[0] !== $organizationId) {
                ++$skippedAmbiguous;

                continue;
            }

            if (!$dryRun) {
                $this->db->query(
                    'INSERT INTO user_organization_memberships (user_id, organization_id, status, default_branch_id)
                     VALUES (?, ?, ?, ?)',
                    [$userId, $organizationId, 'active', $branchId > 0 ? $branchId : null]
                );
            }
            ++$inserted;
        }

        return [
            'scanned' => $scanned,
            'inserted' => $inserted,
            'skipped_existing' => $skippedExisting,
            'skipped_ambiguous' => $skippedAmbiguous,
            'skipped_no_branch' => $skippedNoBranch,
            'skipped_missing_branch_org' => $skippedMissingBranchOrg,
            'dry_run' => $dryRun,
        ];
    }

    private function resolveBranchOrganizationId(int $branchId): ?int
    {
        if ($branchId <= 0) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT b.organization_id AS organization_id
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
             WHERE b.id = ? AND b.deleted_at IS NULL',
            [$branchId]
        );
        if ($row === null || !isset($row['organization_id'])) {
            return null;
        }
        $oid = (int) $row['organization_id'];

        return $oid > 0 ? $oid : null;
    }

    private function membershipRowExists(int $userId, int $organizationId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM user_organization_memberships WHERE user_id = ? AND organization_id = ? LIMIT 1',
            [$userId, $organizationId]
        );

        return $row !== null;
    }

    /**
     * @return list<int>
     */
    private function listActiveOrganizationIdsForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.organization_id AS organization_id
             FROM user_organization_memberships m
             INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
             WHERE m.user_id = ? AND m.status = ?
             ORDER BY m.organization_id ASC',
            [$userId, 'active']
        );
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['organization_id'];
        }

        return $ids;
    }
}
