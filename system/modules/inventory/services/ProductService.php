<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Inventory\Repositories\ProductRepository;

final class ProductService
{
    public const PRODUCT_TYPES = ['retail', 'professional', 'consumable'];

    public function __construct(
        private ProductRepository $repo,
        private StockMovementService $movementService,
        private AuditService $audit,
        private BranchContext $branchContext,
        private ProductTaxonomyAssignabilityService $productTaxonomyAssignability
    ) {
    }

    /**
     * Create product. Keys `product_category_id` / `product_brand_id`: when present in `$data`, `null` clears and
     * `int` assigns (assignability enforced); when omitted, INSERT leaves nullable columns at DB NULL.
     */
    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $tenantBranchId = $this->requireTenantBranchId();
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $data['branch_id'] = $tenantBranchId;
            $productBranch = $this->nullableIntBranch($data['branch_id'] ?? null);
            $catId = $this->nullableTaxonomyId($data['product_category_id'] ?? null);
            $brandId = $this->nullableTaxonomyId($data['product_brand_id'] ?? null);
            $this->productTaxonomyAssignability->assertFinalProductTaxonomy($productBranch, $catId, $brandId, $tenantBranchId);

            $userId = $this->currentUserId();
            $initialQty = (float) ($data['initial_quantity'] ?? 0);
            unset($data['initial_quantity']);

            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;
            $data['stock_quantity'] = 0;
            $id = $this->repo->create($data);

            if ($initialQty !== 0.0) {
                $this->movementService->create([
                    'product_id' => $id,
                    'movement_type' => 'manual_adjustment',
                    'quantity' => $initialQty,
                    'reference_type' => 'product',
                    'reference_id' => $id,
                    'notes' => 'Initial stock on product creation',
                    'branch_id' => $data['branch_id'] ?? null,
                ]);
            }

            $created = $this->repo->findInTenantScope($id, $tenantBranchId);
            $this->audit->log('product_created', 'product', $id, $userId, $data['branch_id'] ?? null, [
                'product' => $created,
                'initial_quantity' => $initialQty,
            ]);

            return $id;
        }, 'product create');
    }

    /**
     * Update product. For each normalized taxonomy column, if the key is absent from {@code $data}, the existing
     * row value is kept; if present (including {@code null}), that value replaces the column after assignability checks.
     */
    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $tenantBranchId = $this->requireTenantBranchId();
            $current = $this->repo->findInTenantScope($id, $tenantBranchId);
            if (!$current) {
                throw new \RuntimeException('Product not found');
            }
            $existingBranch = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($existingBranch);
            $this->branchContext->enforceBranchIdImmutableWhenScoped($data, $existingBranch);

            $effectiveBranch = $this->effectiveProductBranchIdAfterMerge($data, $current);
            $catId = $this->mergedTaxonomyId($data, $current, 'product_category_id');
            $brandId = $this->mergedTaxonomyId($data, $current, 'product_brand_id');
            $this->productTaxonomyAssignability->assertFinalProductTaxonomy($effectiveBranch, $catId, $brandId, $tenantBranchId);

            unset($data['stock_quantity'], $data['initial_quantity']);
            $userId = $this->currentUserId();
            $data['updated_by'] = $userId;
            $this->repo->updateInTenantScope($id, $tenantBranchId, $data);
            $updated = $this->repo->findInTenantScope($id, $tenantBranchId);

            $this->audit->log('product_updated', 'product', $id, $userId, $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => $updated,
            ]);
        }, 'product update');
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $tenantBranchId = $this->requireTenantBranchId();
            $product = $this->repo->findInTenantScope($id, $tenantBranchId);
            if (!$product) {
                throw new \RuntimeException('Product not found');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($product['branch_id'] !== null && $product['branch_id'] !== '' ? (int) $product['branch_id'] : null);

            $this->repo->softDeleteInTenantScope($id, $tenantBranchId);
            $this->audit->log('product_deleted', 'product', $id, $this->currentUserId(), $product['branch_id'] ?? null, [
                'product' => $product,
            ]);
        }, 'product delete');
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function requireTenantBranchId(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for inventory product operations.');
        }

        return $branchId;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $current
     */
    private function effectiveProductBranchIdAfterMerge(array $data, array $current): ?int
    {
        if (array_key_exists('branch_id', $data)) {
            return $this->nullableIntBranch($data['branch_id']);
        }

        return $this->nullableIntBranch($current['branch_id'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $current
     */
    /**
     * Effective taxonomy id for validation: payload wins when the key is present; otherwise current row.
     */
    private function mergedTaxonomyId(array $data, array $current, string $key): ?int
    {
        if (array_key_exists($key, $data)) {
            return $this->nullableTaxonomyId($data[$key]);
        }

        return $this->nullableTaxonomyId($current[$key] ?? null);
    }

    private function nullableIntBranch(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    private function nullableTaxonomyId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
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
            throw new \DomainException('Product operation failed.');
        }
    }
}
