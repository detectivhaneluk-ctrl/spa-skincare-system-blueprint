<?php

declare(strict_types=1);

namespace Core\Organization;

use Core\Errors\AccessDeniedException;

/**
 * Request-scoped current organization. Set by {@see OrganizationContextMiddleware} after {@see \Core\Middleware\BranchContextMiddleware}.
 *
 * **Resolution (FOUNDATION-09 + F-46):** When {@see \Core\Branch\BranchContext} has an active branch, organization is read from that row
 * and must reference an **active** organization (`organizations.deleted_at IS NULL`). When branch context is null: if the authenticated
 * user has exactly one active {@code user_organization_memberships} row to a live org, that org is used ({@see self::MODE_MEMBERSHIP_SINGLE_ACTIVE});
 * else if **exactly one** active organization exists deployment-wide, single-org fallback applies; otherwise null (fail closed — no guess).
 *
 * This is not user-selectable; no session key for organization in this wave.
 */
final class OrganizationContext
{
    public const MODE_BRANCH_DERIVED = 'branch_derived';

    /** F-46: exactly one active {@code user_organization_memberships} row (branch context null). */
    public const MODE_MEMBERSHIP_SINGLE_ACTIVE = 'membership_single_active';

    public const MODE_SINGLE_ACTIVE_ORG_FALLBACK = 'single_active_org_fallback';

    public const MODE_UNRESOLVED_AMBIGUOUS_ORGS = 'unresolved_ambiguous_orgs';

    public const MODE_UNRESOLVED_NO_ACTIVE_ORG = 'unresolved_no_active_org';

    private ?int $currentOrganizationId = null;

    private ?string $resolutionMode = null;

    public function reset(): void
    {
        $this->currentOrganizationId = null;
        $this->resolutionMode = null;
    }

    /**
     * @param self::MODE_* $mode
     */
    public function setFromResolution(?int $organizationId, string $mode): void
    {
        $this->currentOrganizationId = $organizationId;
        $this->resolutionMode = $mode;
    }

    public function getCurrentOrganizationId(): ?int
    {
        return $this->currentOrganizationId;
    }

    /**
     * @return self::MODE_*|null
     */
    public function getResolutionMode(): ?string
    {
        return $this->resolutionMode;
    }

    /**
     * When both branch and organization contexts are non-null, the branch row must belong to this organization.
     * Call with the branch's stored {@see $branchOrganizationId} after load (e.g. from DB).
     *
     * @throws AccessDeniedException when contexts imply a mismatch (defense in depth).
     */
    public function assertBranchBelongsToCurrentOrganization(?int $branchOrganizationId): void
    {
        if ($this->currentOrganizationId === null) {
            return;
        }
        if ($branchOrganizationId === null) {
            return;
        }
        if ((int) $branchOrganizationId !== (int) $this->currentOrganizationId) {
            throw new AccessDeniedException('Branch does not belong to the resolved organization.');
        }
    }
}
