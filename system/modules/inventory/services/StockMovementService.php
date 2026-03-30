<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Inventory\Repositories\StockMovementRepository;

final class StockMovementService
{
    public const MOVEMENT_TYPES = [
        'purchase_in',
        'manual_adjustment',
        'internal_usage',
        'damaged',
        'count_adjustment',
        'sale',
        'sale_reversal',
    ];

    /**
     * Movement types allowed for operator-driven {@see StockMovementController} create/store only.
     * Excludes {@code sale} / {@code sale_reversal} (invoice settlement and other system writers use
     * {@see createWithinTransaction}).
     */
    public const MANUAL_ENTRY_MOVEMENT_TYPES = [
        'purchase_in',
        'manual_adjustment',
        'internal_usage',
        'damaged',
        'count_adjustment',
    ];

    public function __construct(
        private StockMovementRepository $repo,
        private ProductRepository $productRepo,
        private Database $db,
        private AuditService $audit,
        private BranchContext $branchContext
    ) {
    }

    public function create(array $data): int
    {
        $pdo = $this->db->connection();
        $startedTransaction = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }

            $id = $this->createAndApplyStock($data);

            if ($startedTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'inventory.transactional', $e->getMessage(), ['action' => 'stock_movement']);
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \DomainException('Failed to record stock movement atomically.');
        }
    }

    /**
     * Manual inventory UI entry: allowed movement types only; {@code reference_type} / {@code reference_id} are
     * forced null so operators cannot spoof settlement links ({@code invoice_item}, {@code inventory_count}, etc.).
     * Internal callers that need references continue to use {@see create} or {@see createWithinTransaction}.
     */
    public function createManual(array $data): int
    {
        $type = (string) ($data['movement_type'] ?? '');
        if (!in_array($type, self::MANUAL_ENTRY_MOVEMENT_TYPES, true)) {
            throw new \InvalidArgumentException('This movement type cannot be created from the manual stock movement form.');
        }
        $data['reference_type'] = null;
        $data['reference_id'] = null;

        return $this->create($data);
    }

    /**
     * Used by higher-level flows that already opened a DB transaction.
     */
    public function createWithinTransaction(array $data): int
    {
        $pdo = $this->db->connection();
        if (!$pdo->inTransaction()) {
            throw new \DomainException('Stock movement requires an active transaction.');
        }
        return $this->createAndApplyStock($data);
    }

    private function createAndApplyStock(array $data): int
    {
        $tenantBranchId = $this->requireTenantBranchId();
        $productId = (int) ($data['product_id'] ?? 0);
        $movementType = (string) ($data['movement_type'] ?? '');
        $rawQty = (float) ($data['quantity'] ?? 0);

        if (!in_array($movementType, self::MOVEMENT_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid movement type.');
        }
        if ($rawQty == 0.0) {
            throw new \InvalidArgumentException('Quantity must be non-zero.');
        }

        $product = $this->productRepo->findLockedForStockMutationInResolvedOrg($productId, $tenantBranchId);
        if (!$product) {
            throw new \RuntimeException('Product not found');
        }

        if (($product['branch_id'] ?? null) !== null && (int) $product['branch_id'] !== (int) $tenantBranchId) {
            throw new \DomainException('Movement branch must match product branch.');
        }

        $movementBranchId = $tenantBranchId;
        $refType = (string) ($data['reference_type'] ?? '');
        if ($refType === 'invoice_item' && array_key_exists('branch_id', $data)) {
            $rawInvBranch = $data['branch_id'];
            if ($rawInvBranch === null || $rawInvBranch === '') {
                $movementBranchId = null;
            } elseif ((int) $rawInvBranch > 0) {
                $ib = (int) $rawInvBranch;
                if ($ib !== $tenantBranchId) {
                    throw new \DomainException('Stock movement branch must match tenant branch context.');
                }
                $movementBranchId = $ib;
            }
        }

        $signedQty = $this->signedQuantity($movementType, $rawQty);
        $currentQty = (float) ($product['stock_quantity'] ?? 0);
        $nextQty = $currentQty + $signedQty;
        $requireNonNegative = ProductStockQuantityPolicy::requiresNonNegativeOnHandAfter($movementType);
        if ($requireNonNegative && $nextQty < -ProductStockQuantityPolicy::QTY_EPS) {
            throw new \DomainException('Insufficient stock to record this deduction.');
        }

        $userId = $this->currentUserId();
        $movement = [
            'product_id' => $productId,
            'movement_type' => $movementType,
            'quantity' => $signedQty,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'branch_id' => $movementBranchId,
            'created_by' => $userId,
        ];

        $id = $this->repo->create($movement);
        $this->productRepo->updateStockQuantityForStockMutationInResolvedOrg($productId, $tenantBranchId, $nextQty);

        $this->audit->log('stock_movement_created', 'stock_movement', $id, $userId, $movementBranchId !== null ? (int) $movementBranchId : null, [
            'product_id' => $productId,
            'movement_type' => $movementType,
            'quantity' => $signedQty,
            'stock_before' => $currentQty,
            'stock_after' => $nextQty,
            'reference_type' => $movement['reference_type'],
            'reference_id' => $movement['reference_id'],
        ]);

        return $id;
    }

    private function signedQuantity(string $type, float $qty): float
    {
        return match ($type) {
            'purchase_in' => abs($qty),
            'manual_adjustment' => $qty,
            'internal_usage', 'damaged', 'sale' => -abs($qty),
            'sale_reversal' => abs($qty),
            'count_adjustment' => $qty,
            default => throw new \InvalidArgumentException('Invalid movement type.'),
        };
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function requireTenantBranchId(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for inventory stock movement operations.');
        }

        return $branchId;
    }
}
