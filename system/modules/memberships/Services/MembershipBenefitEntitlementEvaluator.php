<?php

declare(strict_types=1);

namespace Modules\Memberships\Services;

/**
 * Canonical decision for **benefit redemption on a calendar date** (appointment day in Y-m-d):
 * operational catalog definition, resolved lifecycle must be {@see MembershipService::LIFECYCLE_ACTIVE},
 * and the date must fall in {@code [starts_at, ends_at]} inclusive.
 *
 * Does **not** cover: visit caps, branch/client/appointment locks, or DB writes — those stay at
 * {@see MembershipService::consumeBenefitForAppointment} and related guards.
 *
 * Callers that hold a {@code client_memberships} row (optionally joined with definition fields) should resolve
 * lifecycle via {@see MembershipService::resolveClientMembershipLifecycleState()} then delegate here so preview/read
 * paths match the write path.
 */
final class MembershipBenefitEntitlementEvaluator
{
    /**
     * @param string $resolvedLifecycleState Value from {@see MembershipService::resolveClientMembershipLifecycleState()}
     */
    public static function isEligibleForBenefitUseOnDate(
        string $resolvedLifecycleState,
        ?string $definitionStatus,
        mixed $definitionDeletedAt,
        string $startsAtYmd,
        string $endsAtYmd,
        string $onDateYmd
    ): bool {
        if (!MembershipBenefitEntitlementPolicy::definitionCatalogAllowsBenefitUse($definitionStatus, $definitionDeletedAt)) {
            return false;
        }
        if ($resolvedLifecycleState !== MembershipService::LIFECYCLE_ACTIVE) {
            return false;
        }
        if ($startsAtYmd === '' || $endsAtYmd === '') {
            return false;
        }

        return $onDateYmd >= $startsAtYmd && $onDateYmd <= $endsAtYmd;
    }
}
