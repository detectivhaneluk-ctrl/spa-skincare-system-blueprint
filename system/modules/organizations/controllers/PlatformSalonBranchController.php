<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Core\Permissions\PermissionService;
use InvalidArgumentException;
use Modules\Organizations\Policies\FounderActionRiskPolicy;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\OrganizationRegistryReadService;
use Modules\Organizations\Services\PlatformGlobalBranchManagementService;
use Throwable;

/**
 * Salon-scoped branch create/edit for the founder control plane.
 * Delegates mutations to {@see PlatformGlobalBranchManagementService}; enforces organization match on every request.
 */
final class PlatformSalonBranchController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private PermissionService $permissions,
        private OrganizationRegistryReadService $orgRead,
        private PlatformGlobalBranchManagementService $branches,
        private FounderSafeActionGuardrailService $guardrail,
    ) {
    }

    public function createForm(int $id): void
    {
        $this->assertViewAuth();
        $id = (int) $id;
        $org = $this->requireSalonForBranchOps($id);
        if (!$this->canManage()) {
            flash('error', 'Not permitted.');
            $this->redirectToSalon($id);
        }
        if (!empty($org['deleted_at'])) {
            flash('error', 'Archived salon.');
            $this->redirectToSalon($id);
        }
        if (!empty($org['suspended_at'])) {
            flash('error', 'Reactivate this salon before adding a branch.');
            $this->redirectToSalon($id);
        }
        $csrf = $this->session->csrfToken();
        $title = 'Add branch';
        $salonName = (string) ($org['name'] ?? '');
        $organizationId = $id;
        $branch = ['name' => '', 'code' => ''];
        $errors = [];
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/branches/create.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function store(int $id): void
    {
        $this->assertManageCsrf();
        $id = (int) $id;
        $actor = $this->requireActorUserId();
        $postedOrg = (int) ($_POST['organization_id'] ?? 0);
        if ($postedOrg !== $id) {
            flash('error', 'Invalid salon context.');
            $this->redirectToSalon($id);
        }
        $org = $this->requireSalonForBranchOps($id);
        if (!empty($org['deleted_at']) || !empty($org['suspended_at'])) {
            flash('error', 'This salon cannot accept new branches right now.');
            $this->redirectToSalon($id);
        }
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_BRANCH_CREATE);
            $name = trim((string) ($_POST['name'] ?? ''));
            $codeRaw = trim((string) ($_POST['code'] ?? ''));
            $code = $codeRaw === '' ? null : $codeRaw;
            $this->branches->createBranch($actor, $id, $name, $code);
            flash('success', 'Branch added.');
            $this->redirectToSalon($id);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Branch was not created.');
        }
        $org = $this->orgRead->getOrganizationById($id);
        $csrf = $this->session->csrfToken();
        $title = 'Add branch';
        $salonName = (string) ($org['name'] ?? '');
        $organizationId = $id;
        $branch = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'code' => trim((string) ($_POST['code'] ?? '')),
        ];
        $errors = [];
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/branches/create.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function editForm(int $id, int $branchId): void
    {
        $this->assertViewAuth();
        $id = (int) $id;
        $branchId = (int) $branchId;
        $org = $this->requireSalonForBranchOps($id);
        if (!$this->canManage()) {
            flash('error', 'Not permitted.');
            $this->redirectToSalon($id);
        }
        if (!empty($org['deleted_at'])) {
            flash('error', 'Archived salon.');
            $this->redirectToSalon($id);
        }
        $row = $this->branches->getBranchWithOrganization($branchId);
        if ($row === null || (int) ($row['organization_id'] ?? 0) !== $id) {
            flash('error', 'Branch not found for this salon.');
            $this->redirectToSalon($id);
        }
        if (!empty($row['deleted_at'])) {
            flash('error', 'This branch is not active.');
            $this->redirectToSalon($id);
        }
        $csrf = $this->session->csrfToken();
        $title = 'Edit branch';
        $salonName = (string) ($org['name'] ?? '');
        $organizationId = $id;
        $branch = [
            'id' => $branchId,
            'name' => (string) ($row['name'] ?? ''),
            'code' => (string) ($row['code'] ?? ''),
        ];
        $errors = [];
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/branches/edit.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function update(int $id, int $branchId): void
    {
        $this->assertManageCsrf();
        $id = (int) $id;
        $branchId = (int) $branchId;
        $actor = $this->requireActorUserId();
        $org = $this->requireSalonForBranchOps($id);
        if (!empty($org['deleted_at'])) {
            flash('error', 'Archived salon.');
            $this->redirectToSalon($id);
        }
        $before = $this->branches->getBranchWithOrganization($branchId);
        if ($before === null || (int) ($before['organization_id'] ?? 0) !== $id) {
            flash('error', 'Branch not found for this salon.');
            $this->redirectToSalon($id);
        }
        if (!empty($before['deleted_at'])) {
            flash('error', 'This branch is not active.');
            $this->redirectToSalon($id);
        }
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_BRANCH_UPDATE);
            $name = trim((string) ($_POST['name'] ?? ''));
            $codeRaw = trim((string) ($_POST['code'] ?? ''));
            $code = $codeRaw === '' ? null : $codeRaw;
            $this->branches->updateBranch($actor, $branchId, $name, $code);
            flash('success', 'Branch saved.');
            $this->redirectToSalon($id);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Branch was not updated.');
        }
        $org = $this->orgRead->getOrganizationById($id);
        $csrf = $this->session->csrfToken();
        $title = 'Edit branch';
        $salonName = (string) ($org['name'] ?? '');
        $organizationId = $id;
        $branch = [
            'id' => $branchId,
            'name' => trim((string) ($_POST['name'] ?? '')),
            'code' => trim((string) ($_POST['code'] ?? '')),
        ];
        $errors = [];
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/branches/edit.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireSalonForBranchOps(int $organizationId): array
    {
        $org = $this->orgRead->getOrganizationById($organizationId);
        if ($org === null) {
            flash('error', 'Salon not found.');
            header('Location: /platform-admin/salons');
            exit;
        }

        return $org;
    }

    private function assertViewAuth(): void
    {
        if ($this->auth->user() === null) {
            header('Location: /login');
            exit;
        }
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
            header('Location: /platform-admin/salons');
            exit;
        }
        if (!$this->permissions->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin/salons');
            exit;
        }
    }

    private function canManage(): bool
    {
        $user = $this->auth->user();

        return $user !== null && $this->permissions->has((int) $user['id'], 'platform.organizations.manage');
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

    private function redirectToSalon(int $organizationId): void
    {
        header('Location: /platform-admin/salons/' . $organizationId . '#branches', true, 302);
        exit;
    }
}
