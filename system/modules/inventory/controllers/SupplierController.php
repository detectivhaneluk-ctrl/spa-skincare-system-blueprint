<?php

declare(strict_types=1);

namespace Modules\Inventory\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Inventory\Repositories\SupplierRepository;
use Modules\Inventory\Services\SupplierService;

final class SupplierController
{
    public function __construct(
        private SupplierRepository $repo,
        private SupplierService $service,
        private BranchDirectory $branchDirectory
    ) {
    }

    public function index(): void
    {
        $tenantBranchId = $this->requireTenantBranchId();
        $search = trim($_GET['search'] ?? '');
        $filters = [
            'search' => $search ?: null,
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $suppliers = $this->repo->listInTenantScope($filters, $tenantBranchId, $perPage, ($page - 1) * $perPage);
        $total = $this->repo->countInTenantScope($filters, $tenantBranchId);
        $flash = flash();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $branches = $this->getBranches();
        require base_path('modules/inventory/views/suppliers/index.php');
    }

    public function create(): void
    {
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $supplier = [];
        require base_path('modules/inventory/views/suppliers/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $supplier = $data;
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/suppliers/create.php');
            return;
        }

        try {
            $id = $this->service->create($data);
            flash('success', 'Supplier created.');
            header('Location: /inventory/suppliers/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $supplier = $data;
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/suppliers/create.php');
        }
    }

    public function show(int $id): void
    {
        $supplier = $this->repo->findInTenantScope($id, $this->requireTenantBranchId());
        if (!$supplier) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($supplier)) {
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/inventory/views/suppliers/show.php');
    }

    public function edit(int $id): void
    {
        $supplier = $this->repo->findInTenantScope($id, $this->requireTenantBranchId());
        if (!$supplier) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($supplier)) {
            return;
        }
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/inventory/views/suppliers/edit.php');
    }

    public function update(int $id): void
    {
        $current = $this->repo->findInTenantScope($id, $this->requireTenantBranchId());
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
            $supplier = array_merge($current, $data);
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/suppliers/edit.php');
            return;
        }

        try {
            $this->service->update($id, $data);
            flash('success', 'Supplier updated.');
            header('Location: /inventory/suppliers/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $supplier = array_merge($current, $data);
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/suppliers/edit.php');
        }
    }

    public function destroy(int $id): void
    {
        $supplier = $this->repo->findInTenantScope($id, $this->requireTenantBranchId());
        if (!$supplier) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($supplier)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Supplier deleted.');
        header('Location: /inventory/suppliers');
        exit;
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            Application::container()->get(\Core\Branch\BranchContext::class)->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }

    private function parseInput(): array
    {
        $branchRaw = trim($_POST['branch_id'] ?? '');
        return [
            'name' => trim($_POST['name'] ?? ''),
            'contact_name' => trim($_POST['contact_name'] ?? '') ?: null,
            'phone' => trim($_POST['phone'] ?? '') ?: null,
            'email' => trim($_POST['email'] ?? '') ?: null,
            'address' => trim($_POST['address'] ?? '') ?: null,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'branch_id' => $branchRaw === '' ? null : (int) $branchRaw,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Name is required.';
        }
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email is invalid.';
        }
        return $errors;
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    private function requireTenantBranchId(): int
    {
        $branchId = Application::container()->get(BranchContext::class)->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for inventory supplier routes.');
        }

        return $branchId;
    }
}
