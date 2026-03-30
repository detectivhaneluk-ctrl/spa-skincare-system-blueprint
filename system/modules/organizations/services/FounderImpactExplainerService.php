<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Modules\Organizations\Repositories\PlatformControlPlaneReadRepository;

/**
 * Cause → impact → recommended action for founder org/branch screens (read-only; reuses registry queries).
 * FOUNDER-OPS-IMPACT-EXPLAINER-01.
 */
final class FounderImpactExplainerService
{
    public function __construct(
        private PlatformControlPlaneReadRepository $reads,
        private PlatformFounderSecurityService $security,
    ) {
    }

    /**
     * @param array<string, mixed> $org Registry row (must include suspended_at, deleted_at)
     * @return array{
     *   lifecycle:string,
     *   branch_count:int,
     *   login_capable_user_count:int,
     *   users_blocked_by_this_org_suspension:int,
     *   tenant_public_surface_note:string,
     *   deployment_kill_switch_count:int,
     *   recommended_action:string,
     *   detail_lines:list<string>,
     *   blast_radius_summary:string,
     *   problem_nature_label:string,
     *   downstream_access_note:string,
     *   if_reactivation_not_appropriate:string,
     *   stays_blocked_until:string
     * }
     */
    public function buildOrganizationImpact(int $organizationId, array $org): array
    {
        $organizationId = max(0, $organizationId);
        $suspended = !empty($org['suspended_at']);
        $deleted = !empty($org['deleted_at']);

        $branchCount = $this->reads->countNonDeletedBranchesForOrganization($organizationId);
        $loginCapable = $this->reads->countActiveUsersWithActiveMembershipOnOrganization($organizationId);
        $blockedBySuspension = $suspended && !$deleted ? $loginCapable : 0;

        $kills = $this->security->getPublicSurfaceKillSwitchState();
        $killCount = 0;
        foreach (['kill_online_booking', 'kill_anonymous_public_apis', 'kill_public_commerce'] as $k) {
            if (!empty($kills[$k])) {
                $killCount++;
            }
        }

        $lifecycle = $deleted ? 'Deleted (registry)' : ($suspended ? 'Suspended' : 'Active');

        $tenantPublic = $deleted
            ? 'This registry row is soft-deleted; treat downstream linkage with care.'
            : ($suspended
                ? 'Tenant booking, staff workspace, and related customer-facing flows for this organization are blocked until reactivation.'
                : 'Tenant-facing surfaces follow normal per-tenant settings while the organization is active.');

        $recommended = $deleted
            ? 'Do not reactivate branches or memberships against a deleted organization without data review — open Branches and Access to verify linkage.'
            : ($suspended
                ? 'If suspension was unintentional, reactivate the organization here. If intentional, communicate to affected staff before clearing memberships.'
                : 'No suspension impact — manage growth via Access and Branches as usual.');

        $lines = [
            'Lifecycle: ' . $lifecycle . '.',
            'Branches in this organization (non-deleted): ' . $branchCount . '.',
            'Login-capable users tied to this organization (active memberships or branch pin fallback): ' . $loginCapable . '.',
        ];
        if ($suspended && !$deleted) {
            $lines[] = 'Users with active membership on this suspended organization are blocked from the normal tenant workspace (' . $blockedBySuspension . ' account(s) tied here).';
        }
        $lines[] = 'Tenant public / customer impact: ' . $tenantPublic;
        if ($killCount > 0) {
            $lines[] = 'Deployment-wide emergency public stops are active (' . $killCount . ' switch(es) on) — review Security in addition to this organization.';
        } else {
            $lines[] = 'Deployment-wide public kill switches: none on (still check Security for audit history).';
        }

        $blastRadius = $deleted
            ? 'Registry: this organization row is soft-deleted. Treat downstream branch and membership linkage as suspect until reviewed.'
            : ($suspended
                ? 'Blast radius: up to ' . $blockedBySuspension . ' login-capable account(s) cannot use the tenant workspace while suspension stands; ' . $branchCount . ' non-deleted branch row(s) remain but tenant workflows for this org stay blocked.'
                : 'Blast radius: no org-wide suspension — tenant entry follows normal access-shape rules for bound users.');

        $problemNature = $deleted
            ? 'Root — organization deleted'
            : ($suspended ? 'Root — organization suspended (policy choke point)' : 'Independent — organization active');

        $downstream = $suspended && !$deleted
            ? 'Incident Center may list “suspended organization binding” and “branches under suspended orgs” as downstream effects of registry suspension — fix org state or memberships first.'
            : ($deleted
                ? 'Downstream incidents often point at integrity or orphan paths — verify Access and Branches after any registry change.'
                : 'Downstream suspension incidents should not apply while this organization stays active.');

        $ifNotReactivate = $suspended && !$deleted
            ? 'If suspension is intentional (closure, contract), do not reactivate — move users to another organization in Access instead.'
            : '—';

        $staysBlocked = $suspended && !$deleted
            ? 'Until suspension clears or memberships move, tenant workspace entry stays blocked for users tied here; renaming branches elsewhere does not lift this.'
            : ($deleted
                ? 'Until registry integrity is restored, assume tenant entry is unsafe for stale linkages.'
                : 'If nothing changes, behavior follows current access-shape and membership — no extra org-wide block from this row.');

        return [
            'lifecycle' => $lifecycle,
            'branch_count' => $branchCount,
            'login_capable_user_count' => $loginCapable,
            'users_blocked_by_this_org_suspension' => $blockedBySuspension,
            'tenant_public_surface_note' => $tenantPublic,
            'deployment_kill_switch_count' => $killCount,
            'recommended_action' => $recommended,
            'detail_lines' => $lines,
            'blast_radius_summary' => $blastRadius,
            'problem_nature_label' => $problemNature,
            'downstream_access_note' => $downstream,
            'if_reactivation_not_appropriate' => $ifNotReactivate,
            'stays_blocked_until' => $staysBlocked,
        ];
    }

    /**
     * @param array<string, mixed> $branch From {@see PlatformGlobalBranchManagementService::getBranchWithOrganization}
     * @return array{
     *   org_lifecycle:string,
     *   operationally_blocked:bool,
     *   blocked_explanation:string,
     *   distinct_user_link_count:int,
     *   recommended_action:string,
     *   name_code_edit_warning:string,
     *   detail_lines:list<string>,
     *   cascade_from_org_suspension:bool,
     *   wrong_page_warning:string,
     *   access_behavior_note:string
     * }
     */
    public function buildBranchImpact(int $branchId, array $branch): array
    {
        $branchId = max(0, $branchId);
        $orgSuspended = !empty($branch['org_suspended_at']);
        $orgDeleted = !empty($branch['org_deleted_at']);
        $branchDeleted = !empty($branch['deleted_at']);

        $userLinks = $this->reads->countDistinctActiveUsersLinkedToBranch($branchId);

        $operationallyBlocked = !$branchDeleted && !$orgDeleted && $orgSuspended;

        $blockedExplanation = $operationallyBlocked
            ? 'The owning organization is suspended — this location cannot be used for normal tenant operations until the organization is reactivated.'
            : ($orgDeleted
                ? 'The owning organization row is deleted — investigate registry integrity before relying on this branch.'
                : 'Branch is available under an active organization (subject to user access-shape).');

        $recommended = $operationallyBlocked
            ? 'Reactivate the organization in Organizations, or move users to another org/branch from Access if this suspension is permanent.'
            : 'To fix sign-in or routing problems, edit memberships and branch pins in Access — renaming this branch alone will not repair access-shape issues.';

        $nameWarning = 'Changing the display name or code updates labels in selectors; it does not grant memberships, fix pins, or resolve tenant_orphan_blocked / suspended-organization states.';

        $orgLifecycle = $orgDeleted ? 'Organization deleted' : ($orgSuspended ? 'Organization suspended' : 'Organization active');

        $lines = [
            'Owning organization: ' . $orgLifecycle . '.',
            $blockedExplanation,
            'Distinct active users linked to this branch (pin or default branch): ' . $userLinks . '.',
            $nameWarning,
        ];

        $wrongPage = $operationallyBlocked
            ? 'You are on a branch edit screen. Renaming a branch does not fix organization suspension — fix the owning organization in Organizations (or move users in Access).'
            : 'If users are still blocked while the organization is active, the fix is usually in Access (membership, pin, or org state) — not only renaming this branch.';

        $accessNote = $operationallyBlocked
            ? 'Access behavior: tenant entry for this location is blocked until the owning organization suspends cleared or users move to another org.'
            : 'Access behavior: branch labels and codes affect selectors only; they do not change membership or suspension policy.';

        return [
            'org_lifecycle' => $orgLifecycle,
            'operationally_blocked' => $operationallyBlocked,
            'blocked_explanation' => $blockedExplanation,
            'distinct_user_link_count' => $userLinks,
            'recommended_action' => $recommended,
            'name_code_edit_warning' => $nameWarning,
            'detail_lines' => $lines,
            'cascade_from_org_suspension' => $operationallyBlocked,
            'wrong_page_warning' => $wrongPage,
            'access_behavior_note' => $accessNote,
        ];
    }
}
