<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

/**
 * Deeper cause / impact / fix clarity for a single user access-shape payload (no new evaluation logic).
 * FOUNDER-OPS-IMPACT-EXPLAINER-01.
 */
final class FounderAccessImpactExplainer
{
    public function __construct(
        private FounderAccessPresenter $presenter,
    ) {
    }

    /**
     * @param array<string, mixed> $shape Output of {@see \Core\Auth\UserAccessShapeService::evaluateForUserIds()} for one user
     * @return array{
     *   exact_cause:string,
     *   impact_on_destination:string,
     *   what_changes_when_fixed:string,
     *   safest_next_step:string,
     *   alternative_fix:?string,
     *   reversibility_note:string,
     *   cause_kind:string,
     *   cause_kind_label:string,
     *   cascade_explanation:?string,
     *   what_stays_unchanged_after_likely_repair:string,
     *   operator_labels:list<string>
     * }
     */
    public function buildUserImpact(array $shape): array
    {
        if (!empty($shape['error'])) {
            $err = (string) ($shape['error'] ?? 'unknown');

            return [
                'exact_cause' => 'Access-shape evaluation did not complete (' . $err . '), so routing rules cannot be applied confidently.',
                'impact_on_destination' => 'Sign-in may land in an unexpected or blocked state until evaluation succeeds.',
                'what_changes_when_fixed' => 'Once evaluation succeeds, destination follows normal rules for roles, memberships, and branches.',
                'safest_next_step' => 'Open Diagnostics for this user, verify data integrity, then retry after correcting missing rows.',
                'alternative_fix' => null,
                'reversibility_note' => 'Fixing data issues is reversible; repeated failed evaluation warrants database review.',
                'cause_kind' => 'evaluation_error',
                'cause_kind_label' => 'Needs data review',
                'cascade_explanation' => null,
                'what_stays_unchanged_after_likely_repair' => 'Unrelated user accounts and organizations remain as they are.',
                'operator_labels' => ['Review data', 'Diagnostics first'],
            ];
        }

        $canon = (string) ($shape['canonical_state'] ?? '');
        $isPlatform = !empty($shape['is_platform_principal']);
        $contr = $shape['contradictions'] ?? [];
        $contr = is_array($contr) ? $contr : [];
        $rep = $shape['suggested_repairs'] ?? [];
        $rep = is_array($rep) ? $rep : [];

        $exactCause = $this->exactCause($canon, $isPlatform, $contr);
        $impactDest = $this->impactDestination($shape);
        $whenFixed = $this->whatChangesWhenFixed($canon, $isPlatform, $contr);
        $safest = $this->safestNextStep($shape);
        $alt = $this->alternativeFix($shape, $safest);
        $rev = $this->reversibility($canon, $contr, $rep);
        $ctx = $this->accessCauseContext($canon, $isPlatform, $contr);

        return [
            'exact_cause' => $exactCause,
            'impact_on_destination' => $impactDest,
            'what_changes_when_fixed' => $whenFixed,
            'safest_next_step' => $safest,
            'alternative_fix' => $alt,
            'reversibility_note' => $rev,
            'cause_kind' => $ctx['kind'],
            'cause_kind_label' => $ctx['label'],
            'cascade_explanation' => $ctx['cascade'],
            'what_stays_unchanged_after_likely_repair' => $ctx['unchanged'],
            'operator_labels' => $ctx['labels'],
        ];
    }

    /**
     * @param list<string> $contr
     *
     * @return array{kind:string,label:string,cascade:?string,unchanged:string,labels:list<string>}
     */
    private function accessCauseContext(string $canon, bool $isPlatform, array $contr): array
    {
        if ($contr !== []) {
            return [
                'kind' => 'platform_boundary',
                'label' => 'Primary — boundary conflict',
                'cascade' => null,
                'unchanged' => 'Other tenants’ data and unrelated organizations stay unchanged when you fix this one principal.',
                'labels' => ['Resolve contradictions', 'Review Diagnostics'],
            ];
        }
        if ($canon === 'tenant_suspended_organization') {
            return [
                'kind' => 'cascading_from_org',
                'label' => 'Downstream — org suspension',
                'cascade' => 'This account is limited because the organization it is bound to is suspended in the registry. The root fix is organization state, not editing branch names alone.',
                'unchanged' => 'Other organizations unaffected by that suspension keep their current behavior.',
                'labels' => ['Needs organization fix first', 'Review branch linkage'],
            ];
        }
        if ($canon === 'tenant_orphan_blocked') {
            return [
                'kind' => 'primary_access_path',
                'label' => 'Primary — missing tenant path',
                'cascade' => null,
                'unchanged' => 'Other users’ memberships and pins stay as-is unless you change them explicitly.',
                'labels' => ['Needs access repair', 'Review branch linkage'],
            ];
        }
        if ($canon === 'deactivated') {
            return [
                'kind' => 'direct_account',
                'label' => 'Primary — account off',
                'cascade' => null,
                'unchanged' => 'Organization and branch rows for other tenants remain unchanged.',
                'labels' => ['Direct account state'],
            ];
        }
        if ($canon === 'founder' || $isPlatform) {
            return [
                'kind' => 'platform_principal',
                'label' => 'Independent — platform plane',
                'cascade' => null,
                'unchanged' => 'Tenant memberships you do not touch stay as-is.',
                'labels' => ['Control plane entry'],
            ];
        }
        if ($canon === 'tenant_multi_branch') {
            return [
                'kind' => 'tenant_multi_branch',
                'label' => 'Independent — multi-branch',
                'cascade' => null,
                'unchanged' => 'Other users’ branch choices and memberships stay as-is unless you edit them.',
                'labels' => ['Review branch linkage'],
            ];
        }
        if ($canon === 'tenant_admin_or_staff_single_branch') {
            return [
                'kind' => 'tenant_single_branch',
                'label' => 'Independent — single branch',
                'cascade' => null,
                'unchanged' => 'Org-wide settings and other users are unchanged by this user’s path alone.',
                'labels' => ['Expected tenant path'],
            ];
        }

        return [
            'kind' => 'tenant_normal',
            'label' => 'Independent — tenant path',
            'cascade' => null,
            'unchanged' => 'Repairs here usually adjust only this user’s pins, memberships, or roles — not whole-tenant defaults.',
            'labels' => ['Review if symptoms persist'],
        ];
    }

    /**
     * @param list<string> $contr
     */
    private function exactCause(string $canon, bool $isPlatform, array $contr): string
    {
        if ($contr !== []) {
            $parts = [];
            foreach ($contr as $c) {
                $parts[] = match ((string) $c) {
                    'platform_founder_role_present_with_additional_tenant_roles' => 'This account has platform_founder plus additional tenant roles.',
                    'platform_principal_has_usable_tenant_branches' => 'This platform principal still has usable tenant branch paths.',
                    default => (string) $c,
                };
            }

            return implode(' ', $parts);
        }
        if ($canon === 'deactivated') {
            return 'The user row is soft-deleted — authentication is disabled regardless of roles.';
        }
        if ($canon === 'founder' || $isPlatform) {
            return 'Platform founder principal — tenant routing should be empty; control-plane access is expected.';
        }

        return match ($canon) {
            'tenant_orphan_blocked' => 'No valid active membership path resolves to a usable branch (orphan / blocked tenant state).',
            'tenant_suspended_organization' => 'The user is bound to a suspended organization through a branch pin and/or active membership on that org.',
            'tenant_admin_or_staff_single_branch' => 'Exactly one usable branch resolves — normal single-location tenant.',
            'tenant_multi_branch' => 'Multiple usable branches resolve — the user must choose a location at entry.',
            default => 'Canonical state: ' . ($canon !== '' ? $canon : 'unknown') . '.',
        };
    }

    /**
     * @param array<string, mixed> $shape
     */
    private function impactDestination(array $shape): string
    {
        $plane = $this->presenter->humanPrincipalPlane($shape);
        $dest = $this->presenter->humanExpectedDestination($shape);

        return 'Principal plane: ' . $plane . '. After sign-in: ' . $dest . '.';
    }

    /**
     * @param list<string> $contr
     */
    private function whatChangesWhenFixed(string $canon, bool $isPlatform, array $contr): string
    {
        if ($contr !== []) {
            return 'Clearing contradictions restores a single clear plane (founder vs tenant) and predictable routing.';
        }
        if ($canon === 'tenant_orphan_blocked') {
            return 'After valid membership + consistent branch access, the user can reach the tenant dashboard or chooser as appropriate.';
        }
        if ($canon === 'tenant_suspended_organization') {
            return 'Reactivating the organization or moving membership removes the suspension block at tenant entry.';
        }
        if ($canon === 'deactivated') {
            return 'Reactivating the account restores sign-in; roles and memberships still apply.';
        }
        if ($isPlatform || $canon === 'founder') {
            return 'No tenant destination until tenant roles and branch paths are removed or the platform role is removed.';
        }

        return 'Fixing data brings behavior in line with the summary above — no change to unrelated tenants.';
    }

    /**
     * @param array<string, mixed> $shape
     */
    private function safestNextStep(array $shape): string
    {
        $human = $this->presenter->humanRepairRecommendations($shape);
        if ($human !== []) {
            return $human[0];
        }
        $canon = (string) ($shape['canonical_state'] ?? '');
        $contr = $shape['contradictions'] ?? [];
        $contr = is_array($contr) ? $contr : [];
        $isPlatform = !empty($shape['is_platform_principal']);
        if ($canon === 'deactivated') {
            return 'If access should be restored, activate the account (reversible) before changing memberships.';
        }
        if ($contr !== [] && $isPlatform) {
            return 'Prefer reviewing Diagnostics, then canonicalize founder roles only after confirming intent.';
        }

        return 'No automatic repair key — use Diagnostics and adjust memberships or organization state as needed.';
    }

    /**
     * @param array<string, mixed> $shape
     */
    private function alternativeFix(array $shape, string $safest): ?string
    {
        $human = $this->presenter->humanRepairRecommendations($shape);
        if (count($human) < 2) {
            return null;
        }
        $alt = $human[1];
        if ($alt === $safest) {
            return null;
        }

        return $alt;
    }

    /**
     * @param list<string> $rep
     */
    private function reversibility(string $canon, array $contr, array $rep): string
    {
        if ($canon === 'deactivated') {
            return 'Activation/deactivation is reversible from this screen while the user row exists.';
        }
        if (in_array('remove_ambiguous_tenant_roles_from_platform_principal_or_remove_platform_role', $rep, true) || $contr !== []) {
            return 'Canonicalizing founder roles is high-impact; restoring previous mixed roles may require re-provisioning — treat as not easily reversible.';
        }
        if (in_array('assign_active_organization_membership_and_consistent_branch_pin', $rep, true)) {
            return 'Membership and branch repairs are reversible by editing memberships and pins again.';
        }

        return 'Prefer small, verifiable changes; keep Diagnostics open while testing.';
    }

    /**
     * Compact diagnosis for Access user detail — layout copy only; uses same shape/impact facts as {@see buildUserImpact()}.
     *
     * @param array<string, mixed> $shape
     * @param array<string, mixed> $userImpact
     * @return array{
     *   title: string,
     *   explanation: string,
     *   action_label: string,
     *   action_href: ?string,
     *   guided_repair_first: bool,
     *   healthy_case: bool
     * }
     */
    public function buildAccessDetailDiagnosis(int $userId, bool $canManage, array $shape, array $userImpact): array
    {
        $base = '/platform-admin/access/' . $userId;
        $diagHref = $base . '/diagnostics';
        $guidedHref = $base . '/guided-repair';
        $activatePreview = '/platform-admin/safe-actions/access/' . $userId . '/user-activate-preview';
        $salonsHref = '/platform-admin/salons';

        $canon = (string) ($shape['canonical_state'] ?? '');
        $isPlatform = !empty($shape['is_platform_principal']);
        $contr = $shape['contradictions'] ?? [];
        $contr = is_array($contr) ? $contr : [];
        $rep = $shape['suggested_repairs'] ?? [];
        $rep = is_array($rep) ? $rep : [];
        $needsBranchMembershipRepair = $canon === 'tenant_orphan_blocked'
            || in_array('assign_active_organization_membership_and_consistent_branch_pin', $rep, true);
        $guidedFirst = $canManage && $needsBranchMembershipRepair;

        if (!empty($shape['error'])) {
            return [
                'title' => 'Access data incomplete',
                'explanation' => 'Access evaluation did not finish; routing cannot be trusted until this is resolved.',
                'action_label' => 'Open diagnostics',
                'action_href' => $diagHref,
                'guided_repair_first' => false,
                'healthy_case' => false,
            ];
        }

        if ($contr !== []) {
            return [
                'title' => 'Needs review',
                'explanation' => (string) ($userImpact['exact_cause'] ?? 'Role or routing conflict detected on this account.'),
                'action_label' => 'Open diagnostics',
                'action_href' => $diagHref,
                'guided_repair_first' => false,
                'healthy_case' => false,
            ];
        }

        if ($canon === 'deactivated') {
            return [
                'title' => 'Login disabled',
                'explanation' => 'This user row is deactivated; sign-in is blocked regardless of roles.',
                'action_label' => $canManage ? 'Enable login' : 'Read-only',
                'action_href' => $canManage ? $activatePreview : null,
                'guided_repair_first' => false,
                'healthy_case' => false,
            ];
        }

        if ($canon === 'tenant_suspended_organization') {
            return [
                'title' => 'Sign-in blocked by organization',
                'explanation' => 'Tenant entry is blocked because the bound organization is suspended.',
                'action_label' => 'Review organization',
                'action_href' => $salonsHref,
                'guided_repair_first' => false,
                'healthy_case' => false,
            ];
        }

        if ($needsBranchMembershipRepair) {
            return [
                'title' => 'Access path incomplete',
                'explanation' => (string) ($userImpact['exact_cause'] ?? 'No valid tenant path resolves to a usable branch.'),
                'action_label' => $canManage ? 'Open guided repair' : 'Read-only',
                'action_href' => $canManage ? $guidedHref : null,
                'guided_repair_first' => $guidedFirst,
                'healthy_case' => false,
            ];
        }

        if ($canon === 'founder' || $isPlatform) {
            return [
                'title' => 'Platform access',
                'explanation' => 'Control-plane principal; tenant routing should not apply unless roles are mixed.',
                'action_label' => 'No action needed',
                'action_href' => null,
                'guided_repair_first' => false,
                'healthy_case' => true,
            ];
        }

        if ($canon === 'tenant_admin_or_staff_single_branch' || $canon === 'tenant_multi_branch') {
            return [
                'title' => 'Access healthy',
                'explanation' => 'Sign-in path looks consistent.',
                'action_label' => 'No action needed',
                'action_href' => null,
                'guided_repair_first' => false,
                'healthy_case' => true,
            ];
        }

        return [
            'title' => 'Needs review',
            'explanation' => (string) ($userImpact['exact_cause'] ?? 'Review access shape and diagnostics.'),
            'action_label' => 'Open diagnostics',
            'action_href' => $diagHref,
            'guided_repair_first' => false,
            'healthy_case' => false,
        ];
    }
}
