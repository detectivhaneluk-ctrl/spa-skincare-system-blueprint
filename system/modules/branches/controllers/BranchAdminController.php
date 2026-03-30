<?php

declare(strict_types=1);

namespace Modules\Branches\Controllers;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Auth\AuthService;
use Core\Branch\BranchDirectory;
use Core\Permissions\PermissionService;

/**
 * Staff branch catalog administration. Deactivate = soft delete ({@see BranchDirectory::softDeleteBranch}).
 * Restoring a soft-deleted branch is not implemented (no established restore pattern in-repo).
 */
final class BranchAdminController
{
    public function __construct(
        private BranchDirectory $branches,
        private AuditService $audit,
        private PermissionService $permissions
    ) {
    }

    private function canManageBranches(): bool
    {
        $user = Application::container()->get(AuthService::class)->user();

        return $user !== null && $this->permissions->has((int) $user['id'], 'branches.manage');
    }

    public function index(): void
    {
        $rows = $this->branches->listAllBranchesForAdmin();
        $canManageBranches = $this->canManageBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        $title = 'Branches';
        require base_path('modules/branches/views/index.php');
    }

    public function create(): void
    {
        $branch = ['name' => '', 'code' => ''];
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $title = 'New branch';
        require base_path('modules/branches/views/create.php');
    }

    public function store(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $codeRaw = trim((string) ($_POST['code'] ?? ''));
        $code = $codeRaw === '' ? null : $codeRaw;
        try {
            $id = $this->branches->createBranch($name, $code);
            $after = $this->branches->getBranchByIdForAdmin($id);
            $this->audit->log('branch_created', 'branch', $id, null, null, ['after' => $after]);
            flash('success', 'Branch created.');
            header('Location: /branches');
            exit;
        } catch (\InvalidArgumentException | \DomainException $e) {
            flash('error', $e->getMessage());
            $branch = ['name' => $name, 'code' => $codeRaw];
            $errors = [$e->getMessage()];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $title = 'New branch';
            require base_path('modules/branches/views/create.php');
        }
    }

    public function edit(int $id): void
    {
        $id = (int) $id;
        $branch = $this->branches->getBranchByIdForAdmin($id);
        if ($branch === null) {
            flash('error', 'Branch not found.');
            header('Location: /branches');
            exit;
        }
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $title = 'Edit branch';
        require base_path('modules/branches/views/edit.php');
    }

    public function update(int $id): void
    {
        $id = (int) $id;
        $before = $this->branches->getBranchByIdForAdmin($id);
        if ($before === null) {
            flash('error', 'Branch not found.');
            header('Location: /branches');
            exit;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $codeRaw = trim((string) ($_POST['code'] ?? ''));
        $code = $codeRaw === '' ? null : $codeRaw;
        try {
            $this->branches->updateBranch($id, $name, $code);
            $after = $this->branches->getBranchByIdForAdmin($id);
            $this->audit->log('branch_updated', 'branch', $id, null, null, [
                'before' => $before,
                'after' => $after,
            ]);
            flash('success', 'Branch updated.');
            header('Location: /branches');
            exit;
        } catch (\InvalidArgumentException | \DomainException $e) {
            flash('error', $e->getMessage());
            $branch = array_merge($before, ['name' => $name, 'code' => $codeRaw]);
            $errors = [$e->getMessage()];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $title = 'Edit branch';
            require base_path('modules/branches/views/edit.php');
        }
    }

    public function destroy(int $id): void
    {
        $id = (int) $id;
        $before = $this->branches->getBranchByIdForAdmin($id);
        if ($before === null) {
            flash('error', 'Branch not found.');
            header('Location: /branches');
            exit;
        }
        try {
            $this->branches->softDeleteBranch($id);
            $this->audit->log('branch_soft_deleted', 'branch', $id, null, null, ['before' => $before]);
            flash('success', 'Branch deactivated (soft-deleted). It will no longer appear in selectors or resolve as current branch.');
        } catch (\InvalidArgumentException | \DomainException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /branches');
        exit;
    }
}
