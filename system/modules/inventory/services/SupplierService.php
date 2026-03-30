<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Inventory\Repositories\SupplierRepository;

final class SupplierService
{
    public function __construct(
        private SupplierRepository $repo,
        private AuditService $audit,
        private BranchContext $branchContext
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $tenantBranchId = $this->requireTenantBranchId();
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $data['branch_id'] = $tenantBranchId;
            $userId = $this->currentUserId();
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;
            $id = $this->repo->create($data);
            $this->audit->log('supplier_created', 'supplier', $id, $userId, $data['branch_id'] ?? null, [
                'supplier' => $this->repo->findInTenantScope($id, $tenantBranchId),
            ]);
            return $id;
        }, 'supplier create');
    }

    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $tenantBranchId = $this->requireTenantBranchId();
            $current = $this->repo->findInTenantScope($id, $tenantBranchId);
            if (!$current) {
                throw new \RuntimeException('Supplier not found');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null);

            $userId = $this->currentUserId();
            $data['updated_by'] = $userId;
            $this->repo->updateInTenantScope($id, $tenantBranchId, $data);
            $updated = $this->repo->findInTenantScope($id, $tenantBranchId);
            $this->audit->log('supplier_updated', 'supplier', $id, $userId, $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => $updated,
            ]);
        }, 'supplier update');
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $tenantBranchId = $this->requireTenantBranchId();
            $supplier = $this->repo->findInTenantScope($id, $tenantBranchId);
            if (!$supplier) {
                throw new \RuntimeException('Supplier not found');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($supplier['branch_id'] !== null && $supplier['branch_id'] !== '' ? (int) $supplier['branch_id'] : null);

            $this->repo->softDeleteInTenantScope($id, $tenantBranchId);
            $this->audit->log('supplier_deleted', 'supplier', $id, $this->currentUserId(), $supplier['branch_id'] ?? null, [
                'supplier' => $supplier,
            ]);
        }, 'supplier delete');
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function requireTenantBranchId(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for inventory supplier operations.');
        }

        return $branchId;
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
            throw new \DomainException('Supplier operation failed.');
        }
    }
}
