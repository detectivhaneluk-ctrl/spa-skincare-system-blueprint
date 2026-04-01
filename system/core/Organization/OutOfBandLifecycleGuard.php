<?php

declare(strict_types=1);

namespace Core\Organization;

use Core\App\Database;

/**
 * Fail-closed guard for worker/CLI/cron execution paths that do not traverse HTTP middleware.
 */
final class OutOfBandLifecycleGuard
{
    public function __construct(
        private Database $db,
        private OrganizationLifecycleGate $lifecycleGate,
    ) {
    }

    public function assertExecutionAllowedForBranch(int $branchId, ?int $organizationId = null, ?int $actorUserId = null): void
    {
        if ($branchId <= 0) {
            throw new \DomainException('Out-of-band execution requires a positive branch scope.');
        }

        $row = $this->db->fetchOne(
            'SELECT organization_id FROM branches WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId]
        );
        if ($row === null) {
            throw new \DomainException('Out-of-band execution branch is unavailable.');
        }

        $resolvedOrganizationId = (int) ($row['organization_id'] ?? 0);
        if ($resolvedOrganizationId <= 0) {
            throw new \DomainException('Out-of-band execution branch has no organization.');
        }

        if ($organizationId !== null && $organizationId > 0 && $resolvedOrganizationId !== $organizationId) {
            throw new \DomainException('Out-of-band execution branch/organization mismatch.');
        }

        if (!$this->lifecycleGate->isOrganizationActive($resolvedOrganizationId)) {
            throw new \DomainException('Out-of-band execution organization is suspended or inactive.');
        }

        if ($this->lifecycleGate->isBranchLinkedToSuspendedOrganization($branchId)) {
            throw new \DomainException('Out-of-band execution branch is linked to a suspended organization.');
        }

        if ($actorUserId !== null && $actorUserId > 0) {
            if ($this->lifecycleGate->isTenantUserBoundToSuspendedOrganization($actorUserId)) {
                throw new \DomainException('Out-of-band actor is bound to a suspended organization.');
            }
            if ($this->lifecycleGate->isTenantUserInactiveStaffAtBranch($actorUserId, $branchId)) {
                throw new \DomainException('Out-of-band actor is inactive for this branch.');
            }
        }
    }
}
