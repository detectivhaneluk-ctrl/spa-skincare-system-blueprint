<?php

declare(strict_types=1);

namespace Modules\Inventory\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Inventory\Repositories\ProductCategoryRepository;
use Modules\Inventory\Services\ProductCategoryService;

final class ProductCategoryController
{
    public function __construct(
        private ProductCategoryRepository $repo,
        private ProductCategoryService $service,
        private BranchDirectory $branchDirectory,
        private BranchContext $branchContext,
    ) {
    }

    public function index(): void
    {
        $opBranch = $this->requireTaxonomyOperationBranchId();
        $categories = $this->repo->listInTenantScope($opBranch);
        $branchNameById = $this->branchNameByIdForTaxonomyIndex();
        $parentIds = [];
        foreach ($categories as $c) {
            $p = $c['parent_id'] ?? null;
            if ($p !== null && $p !== '' && (int) $p > 0) {
                $parentIds[] = (int) $p;
            }
        }
        $parentRowById = $this->repo->mapByIdsForParentLabelLookupInTenantScope($parentIds, $opBranch);
        $categories = array_map(function (array $c) use ($branchNameById, $parentRowById): array {
            $bid = isset($c['branch_id']) && $c['branch_id'] !== '' && $c['branch_id'] !== null ? (int) $c['branch_id'] : null;

            return array_merge($c, [
                '_scope_label' => $this->taxonomyScopeLabelForBranchId($bid, $branchNameById),
                '_parent_label' => $this->productCategoryParentIndexLabel($c, $parentRowById),
            ]);
        }, $categories);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/inventory/views/product-categories/index.php');
    }

    public function create(): void
    {
        $branches = $this->branchDirectory->getActiveBranchesForSelection();
        $errors = [];
        $category = ['sort_order' => 0];
        $parentOptions = $this->parentSelectOptionsForCategory($category);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/inventory/views/product-categories/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $branches = $this->branchDirectory->getActiveBranchesForSelection();
            $category = $data;
            $parentOptions = $this->parentSelectOptionsForCategory($category);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/product-categories/create.php');
            return;
        }
        try {
            $id = $this->service->create($data);
            flash('success', 'Product category created.');
            header('Location: /inventory/product-categories/' . $id);
            exit;
        } catch (\Throwable $e) {
            $branches = $this->branchDirectory->getActiveBranchesForSelection();
            $category = $data;
            $parentOptions = $this->parentSelectOptionsForCategory($category);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $errors['_general'] = $e->getMessage();
            require base_path('modules/inventory/views/product-categories/create.php');
        }
    }

    public function show(int $id): void
    {
        $opBranch = $this->requireTaxonomyOperationBranchId();
        $category = $this->repo->findInTenantScope($id, $opBranch);
        if (!$category) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($category)) {
            return;
        }
        $branchNameById = $this->branchNameByIdForTaxonomyIndex();
        $bid = isset($category['branch_id']) && $category['branch_id'] !== '' && $category['branch_id'] !== null ? (int) $category['branch_id'] : null;
        $scope_label = $this->taxonomyScopeLabelForBranchId($bid, $branchNameById);
        $parentIds = [];
        $rawParent = $category['parent_id'] ?? null;
        if ($rawParent !== null && $rawParent !== '' && (int) $rawParent > 0) {
            $parentIds[] = (int) $rawParent;
        }
        $parentRowById = $this->repo->mapByIdsForParentLabelLookupInTenantScope($parentIds, $opBranch);
        $parent_label = $this->productCategoryParentIndexLabel($category, $parentRowById);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/inventory/views/product-categories/show.php');
    }

    public function edit(int $id): void
    {
        $category = $this->repo->findInTenantScope($id, $this->requireTaxonomyOperationBranchId());
        if (!$category) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($category)) {
            return;
        }
        $branches = $this->branchDirectory->getActiveBranchesForSelection();
        $parentOptions = $this->parentSelectOptionsForCategory($category, $id);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $inventoryBranchReassignmentLocked = Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null;
        require base_path('modules/inventory/views/product-categories/edit.php');
    }

    public function update(int $id): void
    {
        $category = $this->repo->findInTenantScope($id, $this->requireTaxonomyOperationBranchId());
        if (!$category) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($category)) {
            return;
        }
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $branches = $this->branchDirectory->getActiveBranchesForSelection();
            $category = array_merge($category, $data);
            $parentOptions = $this->parentSelectOptionsForCategory($category, $id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $inventoryBranchReassignmentLocked = Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null;
            require base_path('modules/inventory/views/product-categories/edit.php');
            return;
        }
        try {
            $this->service->update($id, $data);
            flash('success', 'Product category updated.');
            header('Location: /inventory/product-categories/' . $id);
            exit;
        } catch (\Throwable $e) {
            $branches = $this->branchDirectory->getActiveBranchesForSelection();
            $category = array_merge($category, $data);
            $parentOptions = $this->parentSelectOptionsForCategory($category, $id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $errors['_general'] = $e->getMessage();
            $inventoryBranchReassignmentLocked = Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null;
            require base_path('modules/inventory/views/product-categories/edit.php');
        }
    }

    public function destroy(int $id): void
    {
        $category = $this->repo->findInTenantScope($id, $this->requireTaxonomyOperationBranchId());
        if (!$category) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($category)) {
            return;
        }
        try {
            $this->service->delete($id);
            flash('success', 'Product category deleted.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /inventory/product-categories');
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

    /**
     * @param array<int, array{id: int|string, name: string, deleted_at: string|null}> $parentRowById
     */
    private function productCategoryParentIndexLabel(array $category, array $parentRowById): string
    {
        $raw = $category['parent_id'] ?? null;
        if ($raw === null || $raw === '') {
            return '—';
        }
        $parentId = (int) $raw;
        if ($parentId <= 0) {
            return '—';
        }
        if (!isset($parentRowById[$parentId])) {
            return 'Missing parent (#' . $parentId . ')';
        }
        $p = $parentRowById[$parentId];
        $name = trim((string) ($p['name'] ?? ''));
        $deleted = !empty($p['deleted_at']);
        if ($deleted) {
            return $name !== '' ? $name . ' (deleted)' : 'Parent #' . $parentId . ' (deleted)';
        }

        return $name !== '' ? $name : '—';
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
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $category Draft or persisted row (uses branch_id when BranchContext is unset).
     * @return list<array<string, mixed>>
     */
    private function parentSelectOptionsForCategory(array $category, ?int $excludeCategoryId = null): array
    {
        $scope = $this->resolveEffectiveCategoryBranchIdForParentSelect($category);
        $rows = $this->repo->listSelectableAsParentForCategoryBranch($scope);
        if ($excludeCategoryId !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r) => (int) $r['id'] !== $excludeCategoryId
            ));
        }

        return $rows;
    }

    private function resolveEffectiveCategoryBranchIdForParentSelect(array $category): ?int
    {
        $ctx = Application::container()->get(BranchContext::class)->getCurrentBranchId();
        if ($ctx !== null) {
            return $ctx;
        }
        $bid = $category['branch_id'] ?? null;
        if ($bid === null || $bid === '') {
            return null;
        }

        return (int) $bid;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseInput(): array
    {
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'branch_id' => trim($_POST['branch_id'] ?? '') ? (int) $_POST['branch_id'] : null,
        ];
        if (array_key_exists('parent_id', $_POST)) {
            $raw = trim((string) ($_POST['parent_id'] ?? ''));
            $data['parent_id'] = $raw === '' ? null : (int) $raw;
        }

        return $data;
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
