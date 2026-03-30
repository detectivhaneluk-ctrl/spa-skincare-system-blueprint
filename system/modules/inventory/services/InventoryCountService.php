<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Permissions\PermissionService;
use Modules\Inventory\Repositories\InventoryCountRepository;
use Modules\Inventory\Repositories\ProductRepository;

final class InventoryCountService
{
    public function __construct(
        private InventoryCountRepository $repo,
        private ProductRepository $productRepo,
        private StockMovementService $movementService,
        private Database $db,
        private PermissionService $permissions,
        private AuditService $audit,
        private BranchContext $branchContext
    ) {
    }

    public function create(array $data): array
    {
        $pdo = $this->db->connection();
        $startedTransaction = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }

            $tenantBranchId = $this->requireTenantBranchId();
            $productId = (int) ($data['product_id'] ?? 0);
            $product = $this->productRepo->findInTenantScope($productId, $tenantBranchId);
            if (!$product) {
                throw new \RuntimeException('Product not found');
            }

            $branchId = $tenantBranchId;
            if (($product['branch_id'] ?? null) !== null && (int) $product['branch_id'] !== (int) $branchId) {
                throw new \DomainException('Count branch must match product branch.');
            }

            $expected = (float) ($product['stock_quantity'] ?? 0);
            $counted = (float) ($data['counted_quantity'] ?? 0);
            if ($counted < 0) {
                throw new \DomainException('Counted quantity cannot be negative.');
            }
            $variance = $counted - $expected;
            $userId = $this->currentUserId();

            $countId = $this->repo->create([
                'product_id' => $productId,
                'expected_quantity' => $expected,
                'counted_quantity' => $counted,
                'variance_quantity' => $variance,
                'notes' => $data['notes'] ?? null,
                'branch_id' => $branchId,
                'created_by' => $userId,
            ]);

            $this->audit->log('inventory_count_created', 'inventory_count', $countId, $userId, $branchId !== null ? (int) $branchId : null, [
                'product_id' => $productId,
                'expected_quantity' => $expected,
                'counted_quantity' => $counted,
                'variance_quantity' => $variance,
            ]);

            $adjusted = false;
            $movementId = null;
            if (!empty($data['apply_adjustment']) && $variance != 0.0) {
                $this->assertAdjustmentPermission($userId);
                $movementId = $this->movementService->createWithinTransaction([
                    'product_id' => $productId,
                    'movement_type' => 'count_adjustment',
                    'quantity' => $variance,
                    'reference_type' => 'inventory_count',
                    'reference_id' => $countId,
                    'notes' => $data['notes'] ?? 'Inventory count adjustment',
                    'branch_id' => $branchId,
                ]);
                $adjusted = true;
                $this->audit->log('inventory_adjustment_applied', 'inventory_count', $countId, $userId, $branchId !== null ? (int) $branchId : null, [
                    'movement_id' => $movementId,
                    'variance_quantity' => $variance,
                ]);
            }

            if ($startedTransaction) {
                $pdo->commit();
            }

            return [
                'count_id' => $countId,
                'variance_quantity' => $variance,
                'adjusted' => $adjusted,
                'movement_id' => $movementId,
            ];
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'inventory.transactional', $e->getMessage(), ['action' => 'inventory_count']);
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \DomainException('Failed to record inventory count atomically.');
        }
    }

    private function assertAdjustmentPermission(?int $userId): void
    {
        if ($userId === null || !$this->permissions->has($userId, 'inventory.adjust')) {
            throw new \DomainException('inventory.adjust permission is required to apply count adjustment.');
        }
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function requireTenantBranchId(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for inventory count operations.');
        }

        return $branchId;
    }
}
