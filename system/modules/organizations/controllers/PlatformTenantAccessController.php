<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Auth\PrincipalPlaneResolver;
use Core\Auth\SessionAuth;
use Core\Auth\UserAccessShapeService;
use InvalidArgumentException;
use Modules\Organizations\Repositories\PlatformTenantAccessReadRepository;
use Modules\Organizations\Policies\FounderActionRiskPolicy;
use Modules\Organizations\Services\FounderAccessPresenter;
use Modules\Organizations\Services\FounderAccessImpactExplainer;
use Modules\Organizations\Services\FounderAccessManagementService;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\TenantUserProvisioningService;
use Throwable;

/**
 * Founder control plane: Access Center (list, user context, diagnostics) + mutations.
 * SUPER-ADMIN-LOGIN-CONTROL-PLANE-CANONICALIZATION-01.
 */
final class PlatformTenantAccessController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private PlatformTenantAccessReadRepository $reads,
        private UserAccessShapeService $accessShape,
        private FounderAccessManagementService $founderAccess,
        private TenantUserProvisioningService $provisioning,
        private FounderAccessPresenter $accessPresenter,
        private FounderAccessImpactExplainer $accessImpactExplainer,
        private FounderSafeActionGuardrailService $guardrail,
    ) {
    }

    public function legacyTenantAccessRedirect(): void
    {
        $qs = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        $target = '/platform-admin/access' . ($qs !== '' ? '?' . $qs : '');
        header('Location: ' . $target, true, 302);
        exit;
    }

    public function index(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = 'Access';
        $presenter = $this->accessPresenter;
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $orgFilter = isset($_GET['org_id']) ? (int) $_GET['org_id'] : 0;
        $shapeFilter = isset($_GET['shape']) ? trim((string) $_GET['shape']) : '';
        if ($shapeFilter !== '' && !in_array($shapeFilter, UserAccessShapeService::ACCESS_SHAPE_CANONICAL_STATES, true)) {
            $shapeFilter = '';
        }

        $userLimit = 200;
        $membershipPivot = $this->reads->userMembershipPivotExists();
        $orgFilterIgnored = $orgFilter > 0 && !$membershipPivot;

        $rows = $this->reads->listUsersForAccessMatrix(
            $userLimit,
            $q === '' ? null : $q,
            $orgFilter > 0 ? $orgFilter : null
        );
        $ids = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $shapes = $this->accessShape->evaluateForUserIds($ids);

        $enriched = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $shape = $shapes[$id] ?? ['user_id' => $id, 'error' => 'shape_eval_missing'];
            if ($shapeFilter !== '' && ($shape['canonical_state'] ?? '') !== $shapeFilter) {
                continue;
            }
            $enriched[] = ['row' => $r, 'shape' => $shape];
        }

        $tenantAccessMeta = [
            'user_limit' => $userLimit,
            'source_row_count' => count($rows),
            'displayed_row_count' => count($enriched),
            'shape_filter' => $shapeFilter,
            'org_filter_ignored' => $orgFilterIgnored,
            'membership_pivot_present' => $membershipPivot,
        ];

        $orgs = $this->reads->listOrganizationsBrief();
        $canManage = Application::container()->get(\Core\Permissions\PermissionService::class)
            ->has((int) $user['id'], 'platform.organizations.manage');
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/access_index.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function provision(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $canManage = Application::container()->get(\Core\Permissions\PermissionService::class)
            ->has((int) $user['id'], 'platform.organizations.manage');
        if (!$canManage) {
            flash('error', 'Provisioning requires platform.organizations.manage.');
            header('Location: /platform-admin/access');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = 'Provision users';
        $orgs = $this->reads->listOrganizationsBrief();
        $branches = $this->reads->listBranchesBrief();
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/access_provision.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function show(int $id): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $id = (int) $id;
        $row = $this->reads->fetchUserForAccessMatrixRow($id);
        if ($row === null) {
            flash('error', 'User not found.');
            header('Location: /platform-admin/access');
            exit;
        }
        $shape = $this->accessShape->evaluateForUserIds([$id])[$id]
            ?? ['user_id' => $id, 'error' => 'shape_eval_missing'];
        $csrf = $this->session->csrfToken();
        $displayName = trim((string) ($row['name'] ?? ''));
        $title = 'Access · ' . ($displayName !== '' ? $displayName : 'User #' . $id);
        $presenter = $this->accessPresenter;
        $orgs = $this->reads->listOrganizationsBrief();
        $branches = $this->reads->listBranchesBrief();
        $canManage = Application::container()->get(\Core\Permissions\PermissionService::class)
            ->has((int) $user['id'], 'platform.organizations.manage');
        $usable = $shape['usable_branch_ids'] ?? [];
        $usableCount = is_array($usable) ? count($usable) : 0;
        $allowSupportEntry = $canManage
            && $usableCount >= 1
            && (string) ($shape['principal_plane'] ?? '') === PrincipalPlaneResolver::TENANT_PLANE
            && (string) ($shape['canonical_state'] ?? '') !== 'deactivated'
            && (string) ($shape['canonical_state'] ?? '') !== 'tenant_suspended_organization';
        $userImpact = $this->accessImpactExplainer->buildUserImpact($shape);
        $accessDetailDiagnosis = $this->accessImpactExplainer->buildAccessDetailDiagnosis($id, $canManage, $shape, $userImpact);
        $flashMsg = flash();
        $founderGuardrailResult = is_array($flashMsg) ? ($flashMsg['founder_guardrail_result'] ?? null) : null;
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/access_user_detail.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function diagnostics(int $id): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $id = (int) $id;
        $row = $this->reads->fetchUserForAccessMatrixRow($id);
        if ($row === null) {
            flash('error', 'User not found.');
            header('Location: /platform-admin/access');
            exit;
        }
        $shape = $this->accessShape->evaluateForUserIds([$id])[$id]
            ?? ['user_id' => $id, 'error' => 'shape_eval_missing'];
        $csrf = $this->session->csrfToken();
        $title = 'Diagnostics · user #' . $id;
        $presenter = $this->accessPresenter;
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/access_user_diagnostics.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function postRepair(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $uid = $this->requirePostPositiveInt('user_id', 'User id');
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ACCESS_REPAIR);
            $this->founderAccess->repairTenantBranchAndMembership(
                $actor,
                $uid,
                $this->requirePostPositiveInt('organization_id', 'Organization id'),
                $this->requirePostPositiveInt('branch_id', 'Branch id'),
                $this->guardrail->auditMetadata(
                    $reason,
                    'Branch pin and membership aligned for tenant access.',
                    'requires_follow_up',
                    ['target_user_id' => $uid]
                )
            );
            flash('success', 'Tenant access repaired (branch pin + membership).');
            flash('founder_guardrail_result', [
                'what_changed' => 'Branch pin and active membership were updated for the selected organization.',
                'what_unchanged' => 'Passwords and unrelated users were not changed.',
                'next_review_url' => '/platform-admin/access/' . $uid,
                'next_review_label' => 'This user (access detail)',
                'rollback_hint' => 'Adjust membership or pin again from Access if this was not the intended fix.',
            ]);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Repair failed; no changes were applied.');
        }
        $this->redirectAfterAccessMutation($uid);
    }

    public function postUserActivate(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $uid = $this->requirePostPositiveInt('user_id', 'User id');
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ACCESS_USER_ACTIVATE);
            $this->founderAccess->setUserActive(
                $actor,
                $uid,
                true,
                $this->guardrail->auditMetadata($reason, 'Account soft-delete cleared; sign-in may resume if shape allows.', 'reversible', [])
            );
            flash('success', 'User activated.');
            flash('founder_guardrail_result', [
                'what_changed' => 'Soft-delete was cleared on this login row.',
                'what_unchanged' => 'Roles and memberships were not changed by activation alone.',
                'next_review_url' => '/platform-admin/access/' . $uid,
                'next_review_label' => 'This user (access detail)',
                'rollback_hint' => 'Deactivate again if the account should stay off.',
            ]);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Activation failed; no changes were applied.');
        }
        $this->redirectAfterAccessMutation($uid);
    }

    public function postUserDeactivate(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $uid = $this->requirePostPositiveInt('user_id', 'User id');
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ACCESS_USER_DEACTIVATE);
            $this->founderAccess->setUserActive(
                $actor,
                $uid,
                false,
                $this->guardrail->auditMetadata($reason, 'Account soft-deleted; sign-in blocked.', 'reversible', [])
            );
            flash('success', 'User deactivated.');
            flash('founder_guardrail_result', [
                'what_changed' => 'This login was soft-deleted; sign-in is blocked.',
                'what_unchanged' => 'Roles and memberships remain in the database.',
                'next_review_url' => '/platform-admin/access/' . $uid,
                'next_review_label' => 'This user (access detail)',
                'rollback_hint' => 'Activate the account again from Access when policy allows.',
            ]);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Deactivation failed; no changes were applied.');
        }
        $this->redirectAfterAccessMutation($uid);
    }

    public function postMembershipSuspend(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $uid = $this->requirePostPositiveInt('user_id', 'User id');
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ACCESS_MEMBERSHIP_SUSPEND);
            $this->founderAccess->setMembershipSuspended(
                $actor,
                $uid,
                $this->requirePostPositiveInt('organization_id', 'Organization id'),
                true
            );
            flash('success', 'Membership suspended.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Membership update failed; no changes were applied.');
        }
        $this->redirectAfterAccessMutation($uid);
    }

    public function postMembershipUnsuspend(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $uid = $this->requirePostPositiveInt('user_id', 'User id');
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ACCESS_MEMBERSHIP_UNSUSPEND);
            $this->founderAccess->setMembershipSuspended(
                $actor,
                $uid,
                $this->requirePostPositiveInt('organization_id', 'Organization id'),
                false
            );
            flash('success', 'Membership reactivated.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Membership update failed; no changes were applied.');
        }
        $this->redirectAfterAccessMutation($uid);
    }

    public function postCanonicalizePlatformPrincipal(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $uid = $this->requirePostPositiveInt('user_id', 'User id');
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ACCESS_CANONICALIZE_PLATFORM_PRINCIPAL);
            $this->founderAccess->stripNonPlatformRolesFromPlatformPrincipal(
                $actor,
                $uid
            );
            flash('success', 'Platform principal roles canonicalized (tenant roles removed, memberships cleared).');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Canonicalization failed; no changes were applied.');
        }
        $this->redirectAfterAccessMutation($uid);
    }

    public function postProvisionAdmin(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ACCESS_PROVISION_ADMIN);
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            $orgId = $this->requirePostPositiveInt('organization_id', 'Organization id');
            $branchId = $this->requirePostPositiveInt('branch_id', 'Branch id');
            $this->assertProvisionBasics($email, $password, $name);
            $this->provisioning->provisionTenantAdmin($email, $password, $name, $orgId, $branchId, $actor);
            flash('success', 'Tenant admin provisioned with valid access shape.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Provisioning failed; no changes were applied.');
        }
        $this->redirectAfterProvisioning();
    }

    public function postProvisionStaff(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ACCESS_PROVISION_STAFF);
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            $orgId = $this->requirePostPositiveInt('organization_id', 'Organization id');
            $branchId = $this->requirePostPositiveInt('branch_id', 'Branch id');
            $this->assertProvisionBasics($email, $password, $name);
            $this->provisioning->provisionTenantStaff($email, $password, $name, $orgId, $branchId, 'reception', $actor);
            flash('success', 'Reception/staff user provisioned with valid access shape.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Provisioning failed; no changes were applied.');
        }
        $this->redirectAfterProvisioning();
    }

    private function redirectAfterProvisioning(): void
    {
        header('Location: /platform-admin/access/provision');
        exit;
    }

    private function redirectAfterAccessMutation(?int $focusUserId): void
    {
        if ($focusUserId !== null && $focusUserId > 0) {
            header('Location: /platform-admin/access/' . $focusUserId);
        } else {
            header('Location: /platform-admin/access');
        }
        exit;
    }

    private function assertManageCsrf(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $name = (string) config('app.csrf_token_name', 'csrf_token');
        $token = (string) ($_POST[$name] ?? '');
        if (!$this->session->validateCsrf($token)) {
            flash('error', 'Invalid security token.');
            header('Location: /platform-admin/access');
            exit;
        }
        if (!Application::container()->get(\Core\Permissions\PermissionService::class)->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin/access');
            exit;
        }
    }

    private function requireActorUserId(): int
    {
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        if ($actor <= 0) {
            flash('error', 'Not authenticated.');
            header('Location: /platform-admin/access');
            exit;
        }

        return $actor;
    }

    private function requirePostPositiveInt(string $field, string $label): int
    {
        $v = (int) ($_POST[$field] ?? 0);
        if ($v <= 0) {
            throw new InvalidArgumentException("{$label} must be a positive integer.");
        }

        return $v;
    }

    /**
     * @param non-empty-string $email
     */
    private function assertProvisionBasics(string $email, string $password, string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if ($email === '') {
            throw new InvalidArgumentException('Email is required.');
        }
        if (strlen($email) > 255) {
            throw new InvalidArgumentException('Email is too long.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email format is invalid.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }
    }
}
