<?php

declare(strict_types=1);

namespace Core\Organization;

use Core\App\Database;
use Core\Errors\AccessDeniedException;

/**
 * FOUNDATION-11 minimal choke-point helper: when {@see OrganizationContext} has a resolved organization id,
 * ensures a target `branches.id` row exists and its `organization_id` matches that context.
 *
 * Does not read request parameters. No-op when org context is unresolved (null) or `branch_id` is null/non-positive.
 * (Distinct from {@see \Core\Branch\BranchContext::assertBranchMatchOrGlobalEntity} / {@see \Core\Branch\BranchContext::assertBranchMatchStrict} — A-006: those gate **request branch context** vs entity rows; this asserts **organization ownership** of a concrete branch id.)
 */
final class OrganizationScopedBranchAssert
{
    public function __construct(
        private Database $db,
        private OrganizationContext $organizationContext,
    ) {
    }

    /**
     * @throws AccessDeniedException when org is resolved and the branch row is missing, or owning organization mismatches context
     */
    public function assertBranchOwnedByResolvedOrganization(?int $branchId): void
    {
        if ($branchId === null || $branchId <= 0) {
            return;
        }
        if ($this->organizationContext->getCurrentOrganizationId() === null) {
            return;
        }

        $row = $this->db->fetchOne(
            'SELECT organization_id FROM branches WHERE id = ? LIMIT 1',
            [$branchId]
        );
        if ($row === null) {
            throw new AccessDeniedException('Branch not found.');
        }
        $orgId = $row['organization_id'] ?? null;
        if ($orgId === null || (int) $orgId <= 0) {
            throw new AccessDeniedException('Branch has no organization assignment.');
        }

        $this->organizationContext->assertBranchBelongsToCurrentOrganization((int) $orgId);
    }
}
