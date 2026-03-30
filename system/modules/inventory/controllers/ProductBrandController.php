<?php

declare(strict_types=1);

namespace Modules\Inventory\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Inventory\Repositories\ProductBrandRepository;
use Modules\Inventory\Services\ProductBrandService;

final class ProductBrandController
{
    public function __construct(
        private ProductBrandRepository $repo,
        private ProductBrandService $service,
        private BranchDirectory $branchDirectory,
        private BranchContext $branchContext,
    ) {
    }

    public function index(): void
    {
        $brands = $this->repo->listInTenantScope($this->requireTaxonomyOperationBranchId());
        $branchNameById = $this->branchNameByIdForTaxonomyIndex();
        $brands = array_map(function (array $b) use ($branchNameById): array {
            $bid = isset($b['branch_id']) && $b['branch_id'] !== '' && $b['branch_id'] !== null ? (int) $b['branch_id'] : null;

            return array_merge($b, [
                '_scope_label' => $this->taxonomyScopeLabelForBranchId($bid, $branchNameById),
            ]);
        }, $brands);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/inventory/views/product-brands/index.php');
    }

    public function create(): void
    {
        $branches = $this->branchDirectory->getActiveBranchesForSelection();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $brand = ['sort_order' => 0];
        require base_path('modules/inventory/views/product-brands/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $branches = $this->branchDirectory->getActiveBranchesForSelection();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $brand = $data;
            require base_path('modules/inventory/views/product-brands/create.php');
            return;
        }
        try {
            $id = $this->service->create($data);
            flash('success', 'Product brand created.');
            header('Location: /inventory/product-brands/' . $id);
            exit;
        } catch (\Throwable $e) {
            $branches = $this->branchDirectory->getActiveBranchesForSelection();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $errors['_general'] = $e->getMessage();
            $brand = $data;
            require base_path('modules/inventory/views/product-brands/create.php');
        }
    }

    public function show(int $id): void
    {
        $brand = $this->repo->findInTenantScope($id, $this->requireTaxonomyOperationBranchId());
        if (!$brand) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($brand)) {
            return;
        }
        $branchNameById = $this->branchNameByIdForTaxonomyIndex();
        $bid = isset($brand['branch_id']) && $brand['branch_id'] !== '' && $brand['branch_id'] !== null ? (int) $brand['branch_id'] : null;
        $scope_label = $this->taxonomyScopeLabelForBranchId($bid, $branchNameById);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/inventory/views/product-brands/show.php');
    }

    public function edit(int $id): void
    {
        $brand = $this->repo->findInTenantScope($id, $this->requireTaxonomyOperationBranchId());
        if (!$brand) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($brand)) {
            return;
        }
        $branches = $this->branchDirectory->getActiveBranchesForSelection();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $inventoryBranchReassignmentLocked = Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null;
        require base_path('modules/inventory/views/product-brands/edit.php');
    }

    public function update(int $id): void
    {
        $brand = $this->repo->findInTenantScope($id, $this->requireTaxonomyOperationBranchId());
        if (!$brand) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($brand)) {
            return;
        }
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $branches = $this->branchDirectory->getActiveBranchesForSelection();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $inventoryBranchReassignmentLocked = Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null;
            $brand = array_merge($brand, $data);
            require base_path('modules/inventory/views/product-brands/edit.php');
            return;
        }
        try {
            $this->service->update($id, $data);
            flash('success', 'Product brand updated.');
            header('Location: /inventory/product-brands/' . $id);
            exit;
        } catch (\Throwable $e) {
            $branches = $this->branchDirectory->getActiveBranchesForSelection();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $errors['_general'] = $e->getMessage();
            $inventoryBranchReassignmentLocked = Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null;
            $brand = array_merge($brand, $data);
            require base_path('modules/inventory/views/product-brands/edit.php');
        }
    }

    public function destroy(int $id): void
    {
        $brand = $this->repo->findInTenantScope($id, $this->requireTaxonomyOperationBranchId());
        if (!$brand) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($brand)) {
            return;
        }
        try {
            $this->service->delete($id);
            flash('success', 'Product brand deleted.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /inventory/product-brands');
        exit;
    }

    /**
     * @return array<int, string>
     */
    private function branchNameByIdForTaxonomyIndex(): array
    {
        $map = [];
        foreach ($this->branchDirectory->getActiveBranchesForSelection() as $b) {
            $map[(int) ($b['id'] ?? 0)] = (string) ($b['name'] ?? '');
        }

        return $map;
    }

    /**
     * @param array<int, string> $branchNameById
     */
    private function taxonomyScopeLabelForBranchId(?int $branchId, array $branchNameById): string
    {
        if ($branchId === null) {
            return 'Global';
        }
        $name = trim($branchNameById[$branchId] ?? '');

        return $name !== '' ? 'Branch: ' . $name : 'Branch: #' . $branchId;
    }

    private function requireTaxonomyOperationBranchId(): int
    {
        $bid = $this->branchContext->getCurrentBranchId();
        if ($bid === null || $bid <= 0) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            exit;
        }

        return $bid;
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

    /**
     * @return array<string, mixed>
     */
    private function parseInput(): array
    {
        return [
            'name' => trim($_POST['name'] ?? ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'branch_id' => trim($_POST['branch_id'] ?? '') ? (int) $_POST['branch_id'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validate(array $data): array
    {
        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Name is required.';
        }

        return $errors;
    }
}
