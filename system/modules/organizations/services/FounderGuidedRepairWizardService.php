<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\Auth\UserAccessShapeService;
use Modules\Organizations\Repositories\PlatformControlPlaneReadRepository;
use Modules\Organizations\Repositories\PlatformTenantAccessReadRepository;

/**
 * Models guided repair flows (diagnosis + safe paths) without performing mutations.
 * FOUNDER-OPS-GUIDED-REPAIR-WIZARDS-FOUNDATION-01.
 */
final class FounderGuidedRepairWizardService
{
    public function __construct(
        private UserAccessShapeService $accessShape,
        private PlatformTenantAccessReadRepository $reads,
        private PlatformControlPlaneReadRepository $controlPlaneReads,
        private TenantUserProvisioningService $provisioning,
        private FounderAccessImpactExplainer $impactExplainer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildBlockedUserWizard(int $userId): array
    {
        $userId = max(0, $userId);
        if ($userId <= 0) {
            return $this->emptyModel('invalid_user', 'Invalid user.');
        }

        $row = $this->reads->fetchUserForAccessMatrixRow($userId);
        if ($row === null) {
            return $this->emptyModel('not_found', 'User not found.');
        }

        $shape = $this->accessShape->evaluateForUserIds([$userId])[$userId]
            ?? ['user_id' => $userId, 'error' => 'shape_eval_missing'];

        $impact = $this->impactExplainer->buildUserImpact($shape);
        $canon = (string) ($shape['canonical_state'] ?? '');
        $hasMem = $this->provisioning->membershipTableExists();

        if (!empty($shape['error'])) {
            return array_merge($this->baseModel($shape, $impact, $row, 'eval_error'), [
                'scenario' => 'eval_error',
                'title' => 'Guided repair unavailable',
                'can_apply' => false,
                'diagnosis' => 'Access-shape evaluation did not complete for this account.',
                'why' => 'The engine reported: ' . (string) ($shape['error'] ?? 'unknown') . '.',
                'recommended_fix' => 'Fix underlying data or schema issues, then re-open this wizard.',
                'alternative_fix' => 'Open Diagnostics for raw payload review.',
                'after_apply' => '—',
                'unchanged' => '—',
                'reversibility' => '—',
            ]);
        }

        if (!empty($shape['is_platform_principal']) || $canon === 'founder') {
            return array_merge($this->baseModel($shape, $impact, $row, 'platform_principal'), [
                'scenario' => 'platform_principal',
                'title' => 'Not a tenant blocked-user case',
                'can_apply' => false,
                'diagnosis' => 'This login is a platform founder / control-plane principal.',
                'why' => 'Guided tenant repair does not apply; mixed founder/tenant roles need canonicalization on the Access user page.',
                'recommended_fix' => 'Use “Canonicalize founder roles” on the user Access page when intentional.',
                'alternative_fix' => null,
                'after_apply' => '—',
                'unchanged' => '—',
                'reversibility' => '—',
            ]);
        }

        if ($canon === 'tenant_admin_or_staff_single_branch' || $canon === 'tenant_multi_branch') {
            return array_merge($this->baseModel($shape, $impact, $row, 'healthy_tenant'), [
                'scenario' => 'healthy_tenant',
                'title' => 'No guided repair needed',
                'can_apply' => false,
                'diagnosis' => 'Tenant access shape is already consistent (usable branch path exists).',
                'why' => 'The access-shape engine does not report a blocked tenant state.',
                'recommended_fix' => 'If the user still cannot sign in, check password resets and unrelated session issues outside this wizard.',
                'alternative_fix' => null,
                'after_apply' => '—',
                'unchanged' => '—',
                'reversibility' => '—',
            ]);
        }

        if ($canon === 'deactivated') {
            return array_merge($this->baseModel($shape, $impact, $row, 'deactivated'), [
                'scenario' => 'deactivated',
                'title' => 'Reactivate account',
                'can_apply' => true,
                'apply_kind' => 'activate_user',
                'diagnosis' => 'The login is soft-deleted — authentication is disabled.',
                'why' => 'Deactivation is an explicit account off-switch in the users table.',
                'recommended_fix' => 'Reactivate the account to allow sign-in again (roles and memberships stay as-is).',
                'alternative_fix' => null,
                'after_apply' => 'User can authenticate again; access-shape is re-evaluated on next login.',
                'unchanged' => 'Roles, memberships, and branch pins are not modified by activation alone.',
                'reversibility' => 'Reversible — you can deactivate again from Access.',
            ]);
        }

        if ($canon === 'tenant_suspended_organization') {
            $recoveryUrls = $this->suspendedOrgRecoveryUrls($shape);

            return array_merge($this->baseModel($shape, $impact, $row, 'tenant_suspended_organization'), [
                'scenario' => 'tenant_suspended_organization',
                'title' => 'Suspended organization binding',
                'can_apply' => false,
                'diagnosis' => 'The user is tied to a suspended organization via branch pin and/or membership.',
                'why' => 'Organization suspension blocks normal tenant workspace entry for bound accounts.',
                'recommended_fix' => $recoveryUrls === []
                    ? 'Clear suspension at the organization (registry) or adjust memberships when policy allows.'
                    : 'Open guided recovery for the suspended organization first, then re-check this user.',
                'alternative_fix' => 'Move the user to a different organization in Access (advanced) after policy review.',
                'after_apply' => 'After the organization is active again, tenant entry should resolve unless memberships still block.',
                'unchanged' => 'Other organizations and users are unchanged.',
                'reversibility' => 'Suspending again is possible from the organization page.',
                'org_recovery_links' => $recoveryUrls,
            ]);
        }

        if ($canon === 'tenant_orphan_blocked') {
            if (!$hasMem) {
                return array_merge($this->baseModel($shape, $impact, $row, 'orphan_no_membership_table'), [
                    'scenario' => 'orphan_no_membership_table',
                    'title' => 'Branch / membership repair unavailable here',
                    'can_apply' => false,
                    'diagnosis' => 'No usable tenant branch path — missing membership pivot.',
                    'why' => 'The membership table is not available; automated repair cannot run safely from this wizard.',
                    'recommended_fix' => 'Restore migrations so memberships exist, or use legacy pin-only flows with engineering support.',
                    'alternative_fix' => null,
                    'after_apply' => '—',
                    'unchanged' => '—',
                    'reversibility' => '—',
                ]);
            }

            $orgs = $this->reads->listOrganizationsBrief();
            $branches = $this->reads->listBranchesBrief();

            return array_merge($this->baseModel($shape, $impact, $row, 'tenant_orphan_blocked'), [
                'scenario' => 'tenant_orphan_blocked',
                'title' => 'Repair branch pin and membership',
                'can_apply' => true,
                'apply_kind' => 'repair_tenant_access',
                'diagnosis' => 'No valid membership path resolves to a usable branch (orphan / blocked tenant state).',
                'why' => 'Active membership and a branch belonging to that organization are required for tenant entry.',
                'recommended_fix' => 'Select an active organization and a non-deleted branch under it; the wizard applies the same repair as the hardened Access endpoint.',
                'alternative_fix' => 'If the user should not be tenant-bound, remove tenant roles elsewhere (outside this wizard).',
                'after_apply' => 'Branch pin and membership row updated; access-shape should resolve to single or multi-branch tenant.',
                'unchanged' => 'Passwords, unrelated memberships, and other users are untouched.',
                'reversibility' => 'Reversible by editing membership/pin again from Access.',
                'orgs' => $orgs,
                'branches' => $branches,
            ]);
        }

        return array_merge($this->baseModel($shape, $impact, $row, 'unknown'), [
            'scenario' => 'unknown',
            'title' => 'Unsupported case',
            'can_apply' => false,
            'diagnosis' => 'Canonical state is not handled by this wizard yet.',
            'why' => $canon,
            'recommended_fix' => 'Use Diagnostics and standard Access tools.',
            'alternative_fix' => null,
            'after_apply' => '—',
            'unchanged' => '—',
            'reversibility' => '—',
        ]);
    }

    /**
     * @param array<string, mixed> $shape
     * @return list<array{organization_id:int,label:string,url:string}>
     */
    private function suspendedOrgRecoveryUrls(array $shape): array
    {
        $members = $shape['organization_memberships'] ?? [];
        if (!is_array($members)) {
            return [];
        }
        $out = [];
        foreach ($members as $m) {
            if (!is_array($m)) {
                continue;
            }
            if (empty($m['org_suspended'])) {
                continue;
            }
            $oid = (int) ($m['organization_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $out[] = [
                'organization_id' => $oid,
                'label' => 'Organization #' . $oid,
                'url' => '/platform-admin/organizations/' . $oid . '/guided-recovery',
            ];
        }

        if ($out === []) {
            $pin = isset($shape['branch_id_pinned']) ? (int) $shape['branch_id_pinned'] : 0;
            if ($pin > 0) {
                $oid = $this->controlPlaneReads->findSuspendedOrganizationIdForBranch($pin);
                if ($oid !== null) {
                    $out[] = [
                        'organization_id' => $oid,
                        'label' => 'Organization #' . $oid . ' (from branch pin)',
                        'url' => '/platform-admin/organizations/' . $oid . '/guided-recovery',
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function baseModel(array $shape, array $impact, ?array $row, string $code): array
    {
        return [
            'wizard_code' => $code,
            'user_id' => (int) ($shape['user_id'] ?? 0),
            'user_email' => (string) ($row['email'] ?? ''),
            'shape' => $shape,
            'impact' => $impact,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyModel(string $code, string $message): array
    {
        return [
            'wizard_code' => $code,
            'error_message' => $message,
            'scenario' => 'error',
            'title' => 'Guided repair',
            'can_apply' => false,
        ];
    }
}
