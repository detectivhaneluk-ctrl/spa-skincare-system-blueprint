<?php

declare(strict_types=1);

namespace Modules\Staff\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Staff\Repositories\StaffGroupRepository;
use Modules\Staff\Services\StaffGroupService;

/**
 * HTML admin interface for staff group management.
 * Thin HTML controller that delegates all business logic to the existing StaffGroupService.
 * The canonical JSON API routes on StaffGroupController remain untouched.
 */
final class StaffGroupAdminController
{
    public function __construct(
        private StaffGroupRepository $repo,
        private StaffGroupService $service,
        private BranchContext $branchContext,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    public function index(): void
    {
        $groups = $this->listGroupsForAdmin();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/staff/views/groups/index.php');
    }

    public function create(): void
    {
        $errors = [];
        $group = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/staff/views/groups/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        try {
            $this->service->create($data);
            flash('success', 'Staff group created.');
            header('Location: /staff/groups/admin');
            exit;
        } catch (\InvalidArgumentException | \DomainException $e) {
            $errors = ['name' => $e->getMessage()];
            $group = $data;
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/staff/views/groups/create.php');
        }
    }

    public function edit(int $id): void
    {
        $group = $this->repo->find($id);
        if (!$group) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->assertBranchAccess($group)) {
            return;
        }
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/staff/views/groups/edit.php');
    }

    public function update(int $id): void
    {
        $group = $this->repo->find($id);
        if (!$group) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->assertBranchAccess($group)) {
            return;
        }
        $data = $this->parseInput();
        // is_active can be toggled via this form
        if (array_key_exists('is_active', $_POST)) {
            $data['is_active'] = !empty($_POST['is_active']);
        }
        try {
            $this->service->update($id, $data);
            flash('success', 'Staff group updated.');
            header('Location: /staff/groups/admin');
            exit;
        } catch (\InvalidArgumentException | \DomainException | \RuntimeException $e) {
            $errors = ['name' => $e->getMessage()];
            $group = array_merge($group, $data);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/staff/views/groups/edit.php');
        }
    }

    private function parseInput(): array
    {
        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
        ];
    }

    private function listGroupsForAdmin(): array
    {
        // Admin list shows all non-deleted groups (active and inactive) for the current branch scope.
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId !== null && $branchId > 0) {
            return $this->repo->listInTenantScope($branchId, [], 200, 0);
        }
        $any = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
        if ($any !== null && $any > 0) {
            return $this->repo->listInTenantScope($any, [], 200, 0);
        }
        return $this->repo->list([], 200, 0);
    }

    private function assertBranchAccess(array $group): bool
    {
        try {
            $bid = $group['branch_id'] !== null && $group['branch_id'] !== '' ? (int) $group['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($bid);
            return true;
        } catch (\DomainException) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }
}
