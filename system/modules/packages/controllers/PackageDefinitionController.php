<?php

declare(strict_types=1);

namespace Modules\Packages\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Packages\Repositories\PackageRepository;
use Modules\Packages\Services\PackageService;

final class PackageDefinitionController
{
    public function __construct(
        private PackageRepository $packages,
        private PackageService $service,
        private BranchDirectory $branchDirectory,
        private BranchContext $branchContext
    ) {
    }

    public function index(): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $filters = [
            'search' => $search ?: null,
            'status' => $status ?: null,
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $packageDefs = $this->packages->listInTenantScope($filters, $tenantBranchId, $perPage, ($page - 1) * $perPage);
        $total = $this->packages->countInTenantScope($filters, $tenantBranchId);
        $flash = flash();
        $branches = $this->getBranches();
        require base_path('modules/packages/views/definitions/index.php');
    }

    public function create(): void
    {
        $this->tenantBranchOrRedirect();
        $package = ['status' => 'active', 'total_sessions' => 1, 'public_online_eligible' => false];
        $errors = [];
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/packages/views/definitions/create.php');
    }

    public function store(): void
    {
        $this->tenantBranchOrRedirect();
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $package = $data;
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/definitions/create.php');
            return;
        }
        try {
            $this->service->createPackage($data);
            flash('success', 'Package definition created.');
            header('Location: /packages');
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $package = $data;
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/definitions/create.php');
        }
    }

    public function edit(int $id): void
    {
        $package = $this->packages->findInTenantScope($id, $this->tenantBranchOrRedirect());
        if (!$package) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($package)) {
            return;
        }
        $errors = [];
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/packages/views/definitions/edit.php');
    }

    public function update(int $id): void
    {
        $current = $this->packages->findInTenantScope($id, $this->tenantBranchOrRedirect());
        if (!$current) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($current)) {
            return;
        }
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $package = array_merge($current, $data);
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/definitions/edit.php');
            return;
        }
        try {
            $this->service->updatePackage($id, $data);
            flash('success', 'Package definition updated.');
            header('Location: /packages');
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $package = array_merge($current, $data);
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/definitions/edit.php');
        }
    }

    private function parseInput(): array
    {
        $branchRaw = trim($_POST['branch_id'] ?? '');
        return [
            'branch_id' => $branchRaw === '' ? null : (int) $branchRaw,
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? '') ?: null,
            'status' => trim($_POST['status'] ?? 'active'),
            'total_sessions' => (int) ($_POST['total_sessions'] ?? 0),
            'validity_days' => trim($_POST['validity_days'] ?? '') === '' ? null : (int) $_POST['validity_days'],
            'price' => trim($_POST['price'] ?? '') === '' ? null : (float) $_POST['price'],
            'public_online_eligible' => isset($_POST['public_online_eligible']) && (string) $_POST['public_online_eligible'] === '1',
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Name is required.';
        }
        if (!in_array($data['status'], PackageService::PACKAGE_STATUSES, true)) {
            $errors['status'] = 'Invalid status.';
        }
        if ((int) $data['total_sessions'] <= 0) {
            $errors['total_sessions'] = 'total_sessions must be greater than zero.';
        }
        if ($data['validity_days'] !== null && (int) $data['validity_days'] <= 0) {
            $errors['validity_days'] = 'validity_days must be greater than zero.';
        }
        if ($data['price'] !== null && (float) $data['price'] < 0) {
            $errors['price'] = 'price cannot be negative.';
        }
        return $errors;
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }

    private function tenantBranchOrRedirect(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            flash('error', 'Tenant branch context is required for package definition routes.');
            header('Location: /packages');
            exit;
        }

        return $branchId;
    }
}
