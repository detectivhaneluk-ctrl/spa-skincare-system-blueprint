<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Core\Permissions\PermissionService;
use InvalidArgumentException;
use Modules\Organizations\Policies\FounderActionRiskPolicy;
use Modules\Organizations\Services\FounderImpactExplainerService;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\PlatformGlobalBranchManagementService;
use Throwable;

/**
 * Founder global branch catalog (/platform-admin/branches). Tenant branch admin remains /branches (org-scoped).
 * PLATFORM-GLOBAL-BRANCH-CONTROL-WIRING-01.
 */
final class PlatformGlobalBranchController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private PlatformGlobalBranchManagementService $branches,
        private PermissionService $permissions,
        private FounderImpactExplainerService $impactExplainer,
        private FounderSafeActionGuardrailService $guardrail,
    ) {
    }

    public function index(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = 'Branches';
        $rows = $this->branches->listBranchesWithOrganizations();
        $canManage = $this->permissions->has((int) $user['id'], 'platform.organizations.manage');
        $flashMsg = flash();
        $founderGuardrailResult = is_array($flashMsg) ? ($flashMsg['founder_guardrail_result'] ?? null) : null;
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/global_branches_index.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function createForm(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = 'New branch';
        $orgs = $this->branches->listOrganizationsForBranchForm();
        if (!$this->permissions->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin/branches');
            exit;
        }
        $branch = ['name' => '', 'code' => '', 'organization_id' => 0];
        $errors = [];
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/global_branches_create.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function store(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_GLOBAL_BRANCH_CREATE);
            $name = trim((string) ($_POST['name'] ?? ''));
            $codeRaw = trim((string) ($_POST['code'] ?? ''));
            $code = $codeRaw === '' ? null : $codeRaw;
            $orgId = $this->requirePostPositiveInt('organization_id', 'Organization');
            $id = $this->branches->createBranch($actor, $orgId, $name, $code);
            flash('success', 'Branch created (organization ' . $orgId . ', id ' . $id . ').');
            header('Location: /platform-admin/branches');
            exit;
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Branch was not created; no changes were applied.');
        }
        $this->redirectBackToCreateForm();
    }

    public function editForm(int $id): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $id = (int) $id;
        $branch = $this->branches->getBranchWithOrganization($id);
        if ($branch === null) {
            flash('error', 'Branch not found.');
            header('Location: /platform-admin/branches');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = 'Edit branch';
        $canManage = $this->permissions->has((int) $user['id'], 'platform.organizations.manage');
        $errors = [];
        $branchImpact = $this->impactExplainer->buildBranchImpact($id, $branch);
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/global_branches_edit.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function update(int $id): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $id = (int) $id;
        $before = $this->branches->getBranchWithOrganization($id);
        if ($before === null) {
            flash('error', 'Branch not found.');
            header('Location: /platform-admin/branches');
            exit;
        }
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_GLOBAL_BRANCH_UPDATE);
            $name = trim((string) ($_POST['name'] ?? ''));
            $codeRaw = trim((string) ($_POST['code'] ?? ''));
            $code = $codeRaw === '' ? null : $codeRaw;
            $this->branches->updateBranch($actor, $id, $name, $code);
            flash('success', 'Branch updated.');
            header('Location: /platform-admin/branches');
            exit;
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Branch was not updated; no changes were applied.');
        }
        $user = $this->auth->user();
        $csrf = $this->session->csrfToken();
        $title = 'Edit branch';
        $branch = array_merge($before, ['name' => trim((string) ($_POST['name'] ?? '')), 'code' => trim((string) ($_POST['code'] ?? ''))]);
        $canManage = $this->permissions->has((int) ($user['id'] ?? 0), 'platform.organizations.manage');
        $errors = [];
        $branchImpact = $this->impactExplainer->buildBranchImpact($id, $branch);
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/global_branches_edit.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function deactivate(int $id): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        $id = (int) $id;
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_GLOBAL_BRANCH_DEACTIVATE);
            $this->branches->softDeleteBranch(
                $actor,
                $id,
                $this->guardrail->auditMetadata(
                    $reason,
                    'Branch soft-deleted; hidden from selectors.',
                    'not_easily_reversible',
                    []
                )
            );
            flash('success', 'Branch deactivated (soft-deleted). Tenant selectors will hide it; historical data stays linked.');
            flash('founder_guardrail_result', [
                'what_changed' => 'This branch row was soft-deleted (deleted_at set).',
                'what_unchanged' => 'Organization and user accounts were not deleted.',
                'next_review_url' => '/platform-admin/branches',
                'next_review_label' => 'Branches list',
                'rollback_hint' => 'There is no one-click restore in this console; coordinate if the branch must return.',
            ]);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Branch was not deactivated; no changes were applied.');
        }
        header('Location: /platform-admin/branches');
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
            header('Location: /platform-admin/branches');
            exit;
        }
        if (!$this->permissions->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin/branches');
            exit;
        }
    }

    private function requireActorUserId(): int
    {
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        if ($actor <= 0) {
            flash('error', 'Not authenticated.');
            header('Location: /platform-admin/branches');
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

    private function redirectBackToCreateForm(): void
    {
        $user = $this->auth->user();
        $csrf = $this->session->csrfToken();
        $title = 'New branch';
        $orgs = $this->branches->listOrganizationsForBranchForm();
        $branch = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'code' => trim((string) ($_POST['code'] ?? '')),
            'organization_id' => (int) ($_POST['organization_id'] ?? 0),
        ];
        $errors = [];
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/global_branches_create.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
        exit;
    }
}
