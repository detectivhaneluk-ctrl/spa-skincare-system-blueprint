<?php

declare(strict_types=1);

namespace Modules\Memberships\Services;

/**
 * Canonical rule for whether a membership definition catalog row may back benefit redemption (appointments, etc.).
 * Soft-deleted definitions must not grant new uses even if {@code status} was left {@code active}.
 *
 * The same predicate applies to other operational touchpoints that should not act on retired catalog rows
 * (e.g. renewal reminder scans) — keep in sync with {@see sqlMembershipDefinitionJoinOperational()}.
 */
final class MembershipBenefitEntitlementPolicy
{
    public static function definitionCatalogAllowsBenefitUse(?string $definitionStatus, mixed $definitionDeletedAt): bool
    {
        if ($definitionDeletedAt !== null && $definitionDeletedAt !== '') {
            return false;
        }

        return ($definitionStatus ?? '') === 'active';
    }

    /**
     * SQL fragment for INNER JOIN … ON (membership_definitions alias) so only operational catalog rows participate.
     * Matches {@see definitionCatalogAllowsBenefitUse()}.
     *
     * @param non-empty-string $alias Table alias (e.g. md)
     */
    public static function sqlMembershipDefinitionJoinOperational(string $alias): string
    {
        return "{$alias}.deleted_at IS NULL AND {$alias}.status = 'active'";
    }
}
