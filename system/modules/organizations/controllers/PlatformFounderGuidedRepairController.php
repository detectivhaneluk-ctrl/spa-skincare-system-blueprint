<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Core\Audit\AuditService;
use InvalidArgumentException;
use Modules\Organizations\Policies\FounderActionRiskPolicy;
use Modules\Organizations\Services\FounderAccessManagementService;
use Modules\Organizations\Services\FounderGuidedRepairWizardService;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\FounderImpactExplainerService;
use Modules\Organizations\Services\OrganizationRegistryMutationService;
use Modules\Organizations\Services\OrganizationRegistryReadService;
use Throwable;

/**
 * Guided repair wizards (reuse hardened mutation services; CSRF + manage permission).
 * FOUNDER-OPS-GUIDED-REPAIR-WIZARDS-FOUNDATION-01.
 */
final class PlatformFounderGuidedRepairController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private FounderGuidedRepairWizardService $wizard,
        private FounderAccessManagementService $founderAccess,
        private OrganizationRegistryReadService $orgRead,
        private OrganizationRegistryMutationService $orgMutation,
        private FounderImpactExplainerService $impactExplainer,
        private AuditService $audit,
        private FounderSafeActionGuardrailService $guardrail,
    ) {
    }

    public function blockedUserWizard(int $id): void
    {
        $this->requireAuthView();
        $id = max(0, $id);
        $model = $this->wizard->buildBlockedUserWizard($id);
        $csrf = $this->session->csrfToken();
        $title = 'Guided repair · user #' . $id;
        $canApply = $this->canManage();
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/guided_repair_blocked_user.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function blockedUserWizardPinShortcut(int $id): void
    {
        header('Location: /platform-admin/access/' . max(0, $id) . '/guided-repair#repair', true, 302);
        exit;
    }

    public function postBlockedUserWizard(int $id): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $id = max(0, $id);
        $action = trim((string) ($_POST['wizard_action'] ?? ''));
        $confirm = !empty($_POST['confirm_apply']);

        if (!$confirm) {
            flash('error', 'Confirm that you have read the impact summary before applying.');
            header('Location: /platform-admin/access/' . $id . '/guided-repair');
            exit;
        }

        $model = $this->wizard->buildBlockedUserWizard($id);
        if (empty($model['can_apply'])) {
            flash('error', 'This repair is not applicable for the current access state.');
            header('Location: /platform-admin/access/' . $id . '/guided-repair');
            exit;
        }

        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_GUIDED_REPAIR_BLOCKED_USER);
            if ($action === 'activate_user' && ($model['apply_kind'] ?? '') === 'activate_user') {
                $this->founderAccess->setUserActive($actor, $id, true);
                flash('success', 'Account reactivated. Review the user Access page to confirm access-shape.');
            } elseif ($action === 'repair_tenant_access' && ($model['apply_kind'] ?? '') === 'repair_tenant_access') {
                $orgId = $this->requirePostPositiveInt('organization_id', 'Organization');
                $branchId = $this->requirePostPositiveInt('branch_id', 'Branch');
                $this->founderAccess->repairTenantBranchAndMembership($actor, $id, $orgId, $branchId);
                flash('success', 'Branch pin and membership updated. Access-shape should improve on next evaluation.');
            } else {
                flash('error', 'Unknown or disallowed action for this wizard state.');
            }
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Repair failed; no changes were applied.');
        }

        header('Location: /platform-admin/access/' . $id . '/guided-repair');
        exit;
    }

    public function orgRecoveryWizard(int $id): void
    {
        $this->requireAuthView();
        $id = max(0, $id);
        $org = $this->orgRead->getOrganizationById($id);
        if ($org === null) {
            flash('error', 'Organization not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        $orgImpact = $this->impactExplainer->buildOrganizationImpact($id, $org);
        $suspended = !empty($org['suspended_at']);
        $csrf = $this->session->csrfToken();
        $title = 'Guided recovery · organization #' . $id;
        $canApply = $this->canManage();
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/guided_repair_org_recovery.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function postOrgRecoveryWizard(int $id): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $id = max(0, $id);
        if (empty($_POST['confirm_reactivate'])) {
            flash('error', 'Confirm reactivation before applying.');
            header('Location: /platform-admin/organizations/' . $id . '/guided-recovery');
            exit;
        }

        $org = $this->orgRead->getOrganizationById($id);
        if ($org === null) {
            flash('error', 'Organization not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        if (empty($org['suspended_at'])) {
            flash('error', 'Organization is not suspended; nothing to reactivate.');
            header('Location: /platform-admin/organizations/' . $id . '/guided-recovery');
            exit;
        }

        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_GUIDED_REPAIR_ORG_RECOVERY);
            $row = $this->orgMutation->reactivateOrganization($id);
            if ($row === null) {
                flash('error', 'Reactivation failed.');
            } else {
                $this->audit->log('founder_guided_repair_organization_reactivated', 'organization', $id, $actor, null, [
                    'organization_id' => $id,
                ]);
                flash('success', 'Organization reactivated. Tenant users bound to this org should be re-evaluated on next sign-in.');
            }
        } catch (Throwable $e) {
            flash('error', 'Reactivation failed; no changes were applied.');
        }

        header('Location: /platform-admin/organizations/' . $id . '/guided-recovery');
        exit;
    }

    private function requireAuthView(): void
    {
        if ($this->auth->user() === null) {
            header('Location: /login');
            exit;
        }
    }

    private function canManage(): bool
    {
        $user = $this->auth->user();

        return $user !== null && Application::container()->get(\Core\Permissions\PermissionService::class)
            ->has((int) $user['id'], 'platform.organizations.manage');
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
            header('Location: /platform-admin');
            exit;
        }
        if (!Application::container()->get(\Core\Permissions\PermissionService::class)->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin');
            exit;
        }
    }

    private function requireActorUserId(): int
    {
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        if ($actor <= 0) {
            flash('error', 'Not authenticated.');
            header('Location: /login');
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
}
