<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\Auth\UserAccessShapeService;
use Modules\Organizations\Repositories\PlatformTenantAccessReadRepository;

/**
 * Read-only previews for dangerous founder actions (provable counts + human copy).
 * FOUNDER-OPS-SAFE-ACTION-GUARDRAILS-01.
 */
final class FounderSafeActionPreviewService
{
    public function __construct(
        private OrganizationRegistryReadService $orgRead,
        private FounderImpactExplainerService $orgImpact,
        private PlatformTenantAccessReadRepository $accessReads,
        private UserAccessShapeService $accessShape,
        private PlatformFounderSecurityService $security,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOrgSuspendPreview(int $organizationId): array
    {
        $org = $this->orgRead->getOrganizationById($organizationId);
        if ($org === null) {
            return ['error' => 'Organization not found.'];
        }
        $impact = $this->orgImpact->buildOrganizationImpact($organizationId, $org);

        return [
            'title' => 'Suspend organization',
            'headline' => 'You are about to suspend organization “' . trim((string) ($org['name'] ?? '')) . '” (#' . $organizationId . ').',
            'preview_bullets' => [
                'Sets suspended_at on this registry row — tenant workspace entry for bound users will be blocked by policy.',
                'Affected branches (non-deleted): ' . (int) ($impact['branch_count'] ?? 0) . '.',
                'Users tied via active membership (or pin fallback): ' . (int) ($impact['login_capable_user_count'] ?? 0) . '.',
            ],
            'what_will_change' => 'Organization suspended_at becomes non-null; tenant flows for this org stop until reactivation.',
            'what_stays' => 'User rows, roles, membership rows, and other organizations are not deleted.',
            'reversibility' => 'reversible',
            'reversibility_detail' => 'Reversible — reactivate the organization when policy allows.',
            'rollback_hint' => 'After suspension, use “Reactivate organization” from the organization page or guided recovery.',
            'post_url' => '/platform-admin/organizations/' . $organizationId . '/suspend',
            'submit_label' => 'Apply organization suspension',
            'require_platform_manage_password_step_up' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOrgReactivatePreview(int $organizationId): array
    {
        $org = $this->orgRead->getOrganizationById($organizationId);
        if ($org === null) {
            return ['error' => 'Organization not found.'];
        }
        $impact = $this->orgImpact->buildOrganizationImpact($organizationId, $org);

        return [
            'title' => 'Reactivate organization',
            'headline' => 'You are about to clear suspension for “' . trim((string) ($org['name'] ?? '')) . '” (#' . $organizationId . ').',
            'preview_bullets' => [
                'Clears suspended_at so tenant operations can resume under normal access-shape rules.',
                'Branches (non-deleted): ' . (int) ($impact['branch_count'] ?? 0) . '.',
                'Users tied via active membership (or pin fallback): ' . (int) ($impact['login_capable_user_count'] ?? 0) . '.',
            ],
            'what_will_change' => 'suspended_at cleared; tenant routing may succeed again for bound users.',
            'what_stays' => 'Memberships and pins are unchanged unless edited elsewhere.',
            'reversibility' => 'reversible',
            'reversibility_detail' => 'You can suspend again from the organization lifecycle controls.',
            'rollback_hint' => 'If this was a mistake, suspend again from the organization page.',
            'post_url' => '/platform-admin/organizations/' . $organizationId . '/reactivate',
            'submit_label' => 'Apply organization reactivation',
            'require_platform_manage_password_step_up' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUserDeactivatePreview(int $userId): array
    {
        $row = $this->accessReads->fetchUserForAccessMatrixRow($userId);
        if ($row === null) {
            return ['error' => 'User not found.'];
        }
        $shape = $this->accessShape->evaluateForUserIds([$userId])[$userId] ?? [];

        return [
            'title' => 'Deactivate login account',
            'headline' => 'You are about to soft-delete user #' . $userId . ' (' . trim((string) ($row['email'] ?? '')) . ').',
            'preview_bullets' => [
                'Sign-in will be disabled; roles and memberships remain in the database.',
                'Access-shape canonical state will treat the account as deactivated.',
            ],
            'what_will_change' => 'users.deleted_at set — authentication blocked.',
            'what_stays' => 'Roles, memberships, and historical records are not removed.',
            'reversibility' => 'reversible',
            'reversibility_detail' => 'Reversible — activate the account again from Access.',
            'rollback_hint' => 'Use Activate account on the same user after review.',
            'post_url' => '/platform-admin/access/user-deactivate',
            'extra_hidden' => ['user_id' => (string) $userId],
            'submit_label' => 'Apply account deactivation',
            'require_platform_manage_password_step_up' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUserActivatePreview(int $userId): array
    {
        $row = $this->accessReads->fetchUserForAccessMatrixRow($userId);
        if ($row === null) {
            return ['error' => 'User not found.'];
        }

        return [
            'title' => 'Activate login account',
            'headline' => 'You are about to clear soft-delete for user #' . $userId . ' (' . trim((string) ($row['email'] ?? '')) . ').',
            'preview_bullets' => [
                'Sign-in becomes possible again if access-shape allows tenant or platform entry.',
                'Roles and memberships are unchanged by activation alone.',
            ],
            'what_will_change' => 'users.deleted_at cleared.',
            'what_stays' => 'Roles, memberships, and branch pins unless edited separately.',
            'reversibility' => 'reversible',
            'reversibility_detail' => 'You can deactivate again from Access.',
            'rollback_hint' => 'Deactivate again if the account should stay off.',
            'post_url' => '/platform-admin/access/user-activate',
            'extra_hidden' => ['user_id' => (string) $userId],
            'submit_label' => 'Apply account activation',
            'require_platform_manage_password_step_up' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAccessRepairPreview(int $userId, int $organizationId, int $branchId): array
    {
        $row = $this->accessReads->fetchUserForAccessMatrixRow($userId);
        if ($row === null) {
            return ['error' => 'User not found.'];
        }
        $org = $this->orgRead->getOrganizationById($organizationId);
        $branches = $this->accessReads->listBranchesBrief();
        $branchLabel = 'branch #' . $branchId;
        foreach ($branches as $b) {
            if ((int) ($b['id'] ?? 0) === $branchId) {
                $branchLabel = (string) ($b['name'] ?? $branchLabel);
                break;
            }
        }

        return [
            'title' => 'Repair tenant branch and membership',
            'headline' => 'You are about to pin user #' . $userId . ' to a branch and upsert active membership.',
            'preview_bullets' => [
                'Organization: ' . trim((string) ($org['name'] ?? ('#' . $organizationId))) . ' (#' . $organizationId . ').',
                'Branch: ' . $branchLabel . ' (#' . $branchId . ').',
                'Refuses platform principals and suspended organizations (enforced server-side).',
            ],
            'what_will_change' => 'users.branch_id updated; membership row set to active with default_branch_id.',
            'what_stays' => 'Passwords and unrelated users unchanged.',
            'reversibility' => 'requires_follow_up',
            'reversibility_detail' => 'Reversible by editing membership/pin again — not a one-click undo here.',
            'rollback_hint' => 'Return to Access user detail and adjust membership or use another repair.',
            'post_url' => '/platform-admin/access/repair',
            'extra_hidden' => [
                'user_id' => (string) $userId,
                'organization_id' => (string) $organizationId,
                'branch_id' => (string) $branchId,
            ],
            'submit_label' => 'Apply branch pin and membership repair',
            'require_platform_manage_password_step_up' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildBranchDeactivatePreview(int $branchId): array
    {
        $row = $this->accessReads->fetchBranchGuardrailRow($branchId);
        if ($row === null) {
            return ['error' => 'Branch not found.'];
        }

        return [
            'title' => 'Deactivate branch (soft-delete)',
            'headline' => 'You are about to soft-delete branch “' . trim((string) ($row['name'] ?? '')) . '” (#' . $branchId . ').',
            'preview_bullets' => [
                'Organization: ' . trim((string) ($row['organization_name'] ?? '')) . ' (#' . (int) ($row['organization_id'] ?? 0) . ').',
                'Branch will disappear from selectors; historical data stays linked.',
            ],
            'what_will_change' => 'branches.deleted_at set for this row.',
            'what_stays' => 'Organization and users are not deleted.',
            'reversibility' => 'not_easily_reversible',
            'reversibility_detail' => 'No one-click restore in this console — re-creating or data repair may be needed.',
            'rollback_hint' => 'Coordinate with engineering if the branch must come back; avoid deactivating unless intended.',
            'post_url' => '/platform-admin/branches/' . $branchId . '/deactivate',
            'submit_label' => 'Apply branch deactivation',
            'require_platform_manage_password_step_up' => true,
        ];
    }

    /**
     * @param array{kill_online_booking:bool,kill_anonymous_public_apis:bool,kill_public_commerce:bool} $desired
     * @return array<string, mixed>
     */
    public function buildKillSwitchPreview(array $desired): array
    {
        $before = $this->security->getPublicSurfaceKillSwitchState();

        return [
            'title' => 'Update public kill switches',
            'headline' => 'You are about to change deployment-wide emergency public stops.',
            'preview_bullets' => [
                'Current — online booking: ' . (!empty($before['kill_online_booking']) ? 'blocking' : 'off'),
                'Current — anonymous public APIs: ' . (!empty($before['kill_anonymous_public_apis']) ? 'blocking' : 'off'),
                'Current — public commerce: ' . (!empty($before['kill_public_commerce']) ? 'blocking' : 'off'),
                'Adjust the checkboxes below to the desired posture, then submit with an operational reason.',
            ],
            'what_will_change' => 'Platform settings keys updated; anonymous/public traffic behavior changes immediately.',
            'what_stays' => 'Authenticated tenant sessions and control-plane access are not logged out by this action alone.',
            'reversibility' => 'reversible',
            'reversibility_detail' => 'Toggle switches back off from Security when the incident is over.',
            'rollback_hint' => 'Return to Security and turn off the same switches after review.',
            'post_url' => '/platform-admin/security/public-surface',
            'kill_desired' => $desired,
            'submit_label' => 'Apply kill switch settings',
            'confirm_checkbox_label' => 'I understand these switches change deployment-wide public/anonymous behavior immediately and are audited.',
            'require_platform_manage_password_step_up' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSupportEntryPreview(int $tenantUserId, ?int $branchId): array
    {
        $row = $this->accessReads->fetchUserForAccessMatrixRow($tenantUserId);
        if ($row === null) {
            return ['error' => 'User not found.'];
        }
        $shape = $this->accessShape->evaluateForUserIds([$tenantUserId])[$tenantUserId] ?? [];
        $usable = $shape['usable_branch_ids'] ?? [];
        $usable = is_array($usable) ? $usable : [];
        if (($branchId === null || $branchId <= 0) && count($usable) === 1) {
            $branchId = (int) $usable[0];
        }

        return [
            'title' => 'Start support entry session',
            'headline' => 'You are about to enter the tenant workspace as user #' . $tenantUserId . ' (' . trim((string) ($row['email'] ?? '')) . ').',
            'preview_bullets' => [
                'This is audited and high-impact — use only for legitimate support.',
                'Branch context: ' . ($branchId !== null && $branchId > 0 ? '#' . $branchId : 'auto / single usable branch'),
                'Usable branches for target: ' . count($usable) . '.',
            ],
            'what_will_change' => 'Session switches to tenant-plane as the target user until you end support or sign out.',
            'what_stays' => 'Data rows are not bulk-changed by starting support entry.',
            'reversibility' => 'requires_follow_up',
            'reversibility_detail' => 'End support entry from tenant UI / founder flows; review audit for session boundaries.',
            'rollback_hint' => 'Stop support entry when finished; do not leave long-lived impersonation.',
            'post_url' => '/platform-admin/support-entry/start',
            'require_support_entry_password_step_up' => true,
            'require_support_entry_control_plane_mfa' => true,
            'extra_hidden' => array_filter([
                'tenant_user_id' => (string) $tenantUserId,
                'branch_id' => $branchId !== null && $branchId > 0 ? (string) $branchId : '',
            ], static fn ($v) => $v !== ''),
            'submit_label' => 'Start support entry session',
            'confirm_checkbox_label' => 'I understand I will enter the tenant workspace as this user until I end support or sign out, and this start is audited.',
        ];
    }
}
