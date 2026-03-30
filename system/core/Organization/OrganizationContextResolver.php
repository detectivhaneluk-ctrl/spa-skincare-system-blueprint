<?php

declare(strict_types=1);

namespace Core\Organization;

use Core\App\Database;
use Core\Auth\AuthService;
use Core\Branch\BranchContext;
use Core\Errors\AccessDeniedException;
use Modules\Organizations\Services\UserOrganizationMembershipReadService;
use Modules\Organizations\Services\UserOrganizationMembershipStrictGateService;

/**
 * Canonical HTTP resolution for {@see OrganizationContext} from {@see BranchContext} + DB truth + optional membership (F-46).
 * FOUNDATION-57: membership-single success path uses {@see UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth}.
 * FOUNDATION-62: branch-derived path enforces membership-backed org authorization when applicable (see {@see self::enforceBranchDerivedMembershipAlignmentIfApplicable}).
 * Does not read request parameters for organization id (no independent org pivot in this wave).
 */
final class OrganizationContextResolver
{
    public function __construct(
        private Database $db,
        private AuthService $auth,
        private UserOrganizationMembershipReadService $membershipRead,
        private UserOrganizationMembershipStrictGateService $membershipStrictGate,
    ) {
    }

    /**
     * Fills {@see OrganizationContext} for the current request. Resets context first.
     *
     * Precedence (F-46): (1) branch-derived org — (2) single active membership for authenticated user — (3) legacy single active org in DB — (4) unresolved.
     *
     * @throws AccessDeniedException when branch context is non-null but the branch has no active organization link,
     *     when branch-derived org is not authorized by active membership (F-62), or when membership-single resolution
     *     cannot satisfy strict membership org truth (F-57)
     */
    public function resolveForHttpRequest(BranchContext $branchContext, OrganizationContext $organizationContext): void
    {
        $organizationContext->reset();

        $branchId = $branchContext->getCurrentBranchId();
        if ($branchId !== null) {
            $orgId = $this->activeOrganizationIdForActiveBranch($branchId);
            if ($orgId === null) {
                throw new AccessDeniedException('Branch is not linked to an active organization.');
            }
            $user = $this->auth->user();
            $userId = $user !== null && isset($user['id']) ? (int) $user['id'] : 0;
            $this->enforceBranchDerivedMembershipAlignmentIfApplicable($orgId, $userId);
            $organizationContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

            return;
        }

        $user = $this->auth->user();
        $userId = $user !== null && isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId > 0) {
            $mCount = $this->membershipRead->countActiveMembershipsForUser($userId);
            if ($mCount === 1) {
                $singleOrgId = $this->membershipRead->getSingleActiveOrganizationIdForUser($userId);
                if ($singleOrgId !== null && $singleOrgId > 0) {
                    try {
                        $assertedOrgId = $this->membershipStrictGate->assertSingleActiveMembershipForOrgTruth($userId);
                    } catch (\RuntimeException $e) {
                        throw new AccessDeniedException(
                            'Unable to resolve organization from single active membership.',
                            0,
                            $e
                        );
                    }
                    $organizationContext->setFromResolution($assertedOrgId, OrganizationContext::MODE_MEMBERSHIP_SINGLE_ACTIVE);

                    return;
                }
            }
            if ($mCount > 1) {
                $organizationContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_AMBIGUOUS_ORGS);

                return;
            }
        }

        $activeCount = $this->queryActiveOrganizationCount();
        if ($activeCount === 0) {
            $organizationContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_NO_ACTIVE_ORG);

            return;
        }
        if ($activeCount > 1) {
            $organizationContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_AMBIGUOUS_ORGS);

            return;
        }

        $row = $this->db->fetchOne(
            'SELECT id FROM organizations WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1'
        );
        $id = $row !== null && isset($row['id']) ? (int) $row['id'] : 0;
        if ($id <= 0) {
            $organizationContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_NO_ACTIVE_ORG);

            return;
        }

        $organizationContext->setFromResolution($id, OrganizationContext::MODE_SINGLE_ACTIVE_ORG_FALLBACK);
    }

    /**
     * FOUNDATION-61 / F-62: on branch-derived path only, after branch org id is known and before {@see OrganizationContext::MODE_BRANCH_DERIVED}.
     * Read-only; uses {@see UserOrganizationMembershipReadService} only. Does not call {@see UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth}.
     *
     * Cases A/B/C (skip): {@code $userId <= 0}; {@code countActiveMembershipsForUser === 0} (membership table absent or zero active rows).
     * Case D/F (allow): single org matches branch org; or multiple memberships and branch org is in the list.
     * Case E (deny): single active membership org ≠ branch-derived org.
     * Case G (deny): multiple active memberships and branch org ∉ list.
     */
    private function enforceBranchDerivedMembershipAlignmentIfApplicable(int $branchDerivedOrgId, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $mCount = $this->membershipRead->countActiveMembershipsForUser($userId);
        if ($mCount === 0) {
            return;
        }
        if ($mCount === 1) {
            $singleOrgId = $this->membershipRead->getSingleActiveOrganizationIdForUser($userId);
            if ($singleOrgId === null || $singleOrgId <= 0) {
                return;
            }
            if ((int) $singleOrgId !== (int) $branchDerivedOrgId) {
                throw new AccessDeniedException(
                    'Current branch organization is not authorized by the user\'s active organization membership.'
                );
            }

            return;
        }
        $orgIds = $this->membershipRead->listActiveOrganizationIdsForUser($userId);
        if (!in_array((int) $branchDerivedOrgId, $orgIds, true)) {
            throw new AccessDeniedException(
                'Current branch organization is not among the user\'s active organization memberships.'
            );
        }
    }

    private function activeOrganizationIdForActiveBranch(int $branchId): ?int
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
        if ($row === null || $row['organization_id'] === null || (int) $row['organization_id'] <= 0) {
            return null;
        }

        return (int) $row['organization_id'];
    }

    /**
     * Read-only count for policy gates; does not alter {@see resolveForHttpRequest} behavior.
     */
    public function countActiveOrganizations(): int
    {
        return $this->queryActiveOrganizationCount();
    }

    private function queryActiveOrganizationCount(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM organizations WHERE deleted_at IS NULL');

        return (int) ($row['c'] ?? 0);
    }
}
