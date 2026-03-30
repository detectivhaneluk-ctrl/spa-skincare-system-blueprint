<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Inventory\Repositories\ProductBrandRepository;
use Modules\Inventory\Repositories\ProductRepository;

final class ProductBrandService
{
    public function __construct(
        private ProductBrandRepository $repo,
        private ProductRepository $productRepo,
        private AuditService $audit,
        private BranchContext $branchContext
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $data['name'] = trim((string) ($data['name'] ?? ''));
            $this->assertNameValidAndUnique($data['name'], $data['branch_id'] ?? null, null);
            $id = $this->repo->create($data);
            $this->audit->log('product_brand_created', 'product_brand', $id, $this->userId(), $data['branch_id'] ?? null, [
                'product_brand' => $data,
            ]);

            return $id;
        }, 'product brand create');
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
            if (array_key_exists('name', $data)) {
                $data['name'] = trim((string) $data['name']);
                $branch = array_key_exists('branch_id', $data)
                    ? ($data['branch_id'] === null || $data['branch_id'] === '' ? null : (int) $data['branch_id'])
                    : (($current['branch_id'] ?? null) !== null && ($current['branch_id'] ?? '') !== '' ? (int) $current['branch_id'] : null);
                $this->assertNameValidAndUnique($data['name'], $branch, $id);
            }
            $this->repo->update($id, $data);
            $this->audit->log('product_brand_updated', 'product_brand', $id, $this->userId(), $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => array_merge($current, $data),
            ]);
        }, 'product brand update');
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $opBranch = $this->requireOperationBranchIdForTaxonomyMutation();
            $row = $this->repo->findInTenantScope($id, $opBranch);
            if (!$row) {
                throw new \RuntimeException('Not found');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null);
            $productsDetached = $this->productRepo->detachActiveProductsFromBrand($id);
            $this->repo->softDeleteLiveInResolvedTenantCatalogScope($id);
            $this->audit->log('product_brand_deleted', 'product_brand', $id, $this->userId(), $row['branch_id'] ?? null, [
                'product_brand' => $row,
                'products_cleared_product_brand_id' => $productsDetached,
            ]);
        }, 'product brand delete');
    }

    private function requireOperationBranchIdForTaxonomyMutation(): int
    {
        $bid = $this->branchContext->getCurrentBranchId();
        if ($bid === null || $bid <= 0) {
            throw new \DomainException('Branch context is required for product brand changes.');
        }

        return $bid;
    }

    private function assertNameValidAndUnique(string $name, ?int $branchId, ?int $excludeId): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Brand name is required.');
        }
        if ($this->repo->findOtherLiveByScopeAndTrimmedName($branchId, $name, $excludeId) !== null) {
            throw new \DomainException('A product brand with the same trimmed name already exists in this branch scope.');
        }
    }

    private function userId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
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
            throw new \DomainException('Product brand operation failed.');
        }
    }
}
