<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Inventory\Repositories\ProductCategoryRepository;
use Modules\Inventory\Repositories\ProductRepository;

final class ProductCategoryService
{
    public function __construct(
        private ProductCategoryRepository $repo,
        private ProductRepository $productRepo,
        private AuditService $audit,
        private BranchContext $branchContext
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $this->normalizeAndAssertCategoryName($data, null);
            $this->assertNoDuplicateTrimmedCategoryNameCreate($data);
            $this->validateParentHierarchy(null, $data);
            $id = $this->repo->create($data);
            $this->audit->log('product_category_created', 'product_category', $id, $this->userId(), $data['branch_id'] ?? null, [
                'product_category' => $data,
            ]);

            return $id;
        }, 'product category create');
    }

    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $opBranch = $this->requireOperationBranchIdForTaxonomyMutation();
            $current = $this->repo->findInTenantScope($id, $opBranch);
            if (!$current) {
                throw new \RuntimeException('Not found');
            }
            $existingBranch = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($existingBranch);
            $this->branchContext->enforceBranchIdImmutableWhenScoped($data, $existingBranch);
            $this->normalizeAndAssertCategoryName($data, $current);
            if (array_key_exists('name', $data)) {
                $this->assertNoDuplicateTrimmedCategoryNameUpdate($id, $data, $current);
            }
            $this->validateParentHierarchy($id, $data, $current);
            $this->repo->updateInResolvedTenantCatalogScope($id, $data);
            $this->audit->log('product_category_updated', 'product_category', $id, $this->userId(), $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => array_merge($current, $data),
            ]);
        }, 'product category update');
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $opBranch = $this->requireOperationBranchIdForTaxonomyMutation();
            $cat = $this->repo->findInTenantScope($id, $opBranch);
            if (!$cat) {
                throw new \RuntimeException('Not found');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($cat['branch_id'] !== null && $cat['branch_id'] !== '' ? (int) $cat['branch_id'] : null);
            $productsDetached = $this->productRepo->detachActiveProductsFromCategory($id);
            $this->repo->clearChildParentLinks($id);
            $this->repo->softDeleteLiveInResolvedTenantCatalogScope($id);
            $this->audit->log('product_category_deleted', 'product_category', $id, $this->userId(), $cat['branch_id'] ?? null, [
                'product_category' => $cat,
                'products_cleared_product_category_id' => $productsDetached,
            ]);
        }, 'product category delete');
    }

    private function requireOperationBranchIdForTaxonomyMutation(): int
    {
        $bid = $this->branchContext->getCurrentBranchId();
        if ($bid === null || $bid <= 0) {
            throw new \DomainException('Branch context is required for product category changes.');
        }

        return $bid;
    }

    /**
     * Parent row loads need tenant scope; create may run before session branch is pinned, so allow branch_id from payload/current.
     */
    private function operationBranchIdForTaxonomyParentLookup(array $data, ?array $current): int
    {
        $bid = $this->branchContext->getCurrentBranchId();
        if ($bid !== null && $bid > 0) {
            return $bid;
        }
        $merged = array_merge($current ?? [], $data);
        $raw = $merged['branch_id'] ?? null;
        if ($raw !== null && $raw !== '') {
            $h = (int) $raw;
            if ($h > 0) {
                return $h;
            }
        }
        throw new \DomainException('Branch context is required to resolve product category parent.');
    }

    private function userId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current for update
     */
    private function validateParentHierarchy(?int $categoryId, array $data, ?array $current = null): void
    {
        $parentId = null;
        if (array_key_exists('parent_id', $data)) {
            $raw = $data['parent_id'];
            $parentId = ($raw === null || $raw === '') ? null : (int) $raw;
        } elseif ($current !== null) {
            $pb = $current['parent_id'] ?? null;
            $parentId = ($pb !== null && $pb !== '') ? (int) $pb : null;
        }

        if ($parentId === null) {
            return;
        }

        $parentLookupBranch = $this->operationBranchIdForTaxonomyParentLookup($data, $current);
        $this->repo->assertValidParentAssignment($categoryId, $parentId, $parentLookupBranch);
        $parentRow = $this->repo->findInTenantScope($parentId, $parentLookupBranch);
        if ($parentRow === null) {
            throw new \InvalidArgumentException('Parent product category not found.');
        }

        $childBranch = $this->effectiveCategoryBranchId($data, $current);
        $this->assertParentBranchScope($childBranch, $parentRow);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current
     */
    private function effectiveCategoryBranchId(array $data, ?array $current): ?int
    {
        if (array_key_exists('branch_id', $data)) {
            $b = $data['branch_id'];
            if ($b === null || $b === '') {
                return null;
            }

            return (int) $b;
        }
        if ($current === null) {
            return null;
        }
        $b = $current['branch_id'] ?? null;

        return ($b !== null && $b !== '') ? (int) $b : null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current
     */
    private function normalizeAndAssertCategoryName(array &$data, ?array $current): void
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
            if ($data['name'] === '') {
                throw new \InvalidArgumentException('Product category name is required.');
            }
        } elseif ($current === null) {
            throw new \InvalidArgumentException('Product category name is required.');
        } else {
            $t = trim((string) ($current['name'] ?? ''));
            if ($t === '') {
                throw new \InvalidArgumentException('Product category name is required.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertNoDuplicateTrimmedCategoryNameCreate(array $data): void
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return;
        }
        $branch = $this->effectiveCategoryBranchId($data, null);
        if ($this->repo->findCanonicalLiveByScopeAndTrimmedName($branch, $name) !== null) {
            throw new \DomainException('A product category with the same trimmed name already exists in this branch scope.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $current
     */
    private function assertNoDuplicateTrimmedCategoryNameUpdate(int $id, array $data, array $current): void
    {
        $name = array_key_exists('name', $data)
            ? trim((string) $data['name'])
            : trim((string) ($current['name'] ?? ''));
        if ($name === '') {
            return;
        }
        $branch = $this->effectiveCategoryBranchId($data, $current);
        if ($this->repo->findOtherLiveByScopeAndTrimmedName($branch, $name, $id) !== null) {
            throw new \DomainException('A product category with the same trimmed name already exists in this branch scope.');
        }
    }

    /**
     * Branch-scoped parent must match child branch; global parent allowed for branch-scoped children.
     */
    private function assertParentBranchScope(?int $childBranchId, array $parentRow): void
    {
        $pBranch = $parentRow['branch_id'] ?? null;
        $pBranch = ($pBranch !== null && $pBranch !== '') ? (int) $pBranch : null;
        if ($pBranch === null) {
            return;
        }
        if ($childBranchId === null) {
            throw new \DomainException('A global product category cannot have a branch-scoped parent.');
        }
        if ($childBranchId !== $pBranch) {
            throw new \DomainException('Parent product category must belong to the same branch as the child.');
        }
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $db = Application::container()->get(\Core\App\Database::class);
        $pdo = $db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $callback();
            if ($started) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'inventory.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Product category operation failed.');
        }
    }
}
