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

    /**
     * Non-throwing variant for use inside global sweep iterations (e.g. per-row in a cron pass).
     * Returns false when the branch or its parent organization is suspended, deleted, or unavailable.
     * Actor checks are skipped — intended for system-initiated background sweeps with no human actor.
     * Callers that iterate many rows for the same branch should cache results locally to avoid N+1 queries.
     */
    public function isExecutionAllowedForBranch(int $branchId, ?int $organizationId = null): bool
    {
        if ($branchId <= 0) {
            return false;
        }
        try {
            $this->assertExecutionAllowedForBranch($branchId, $organizationId);
            return true;
        } catch (\DomainException) {
            return false;
        }
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
