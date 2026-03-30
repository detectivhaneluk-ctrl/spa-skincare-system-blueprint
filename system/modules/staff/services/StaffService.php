<?php

declare(strict_types=1);

namespace Modules\Staff\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Tenant\TenantOwnedDataScopeGuard;
use Modules\Staff\Repositories\StaffRepository;

final class StaffService
{
    public function __construct(
        private StaffRepository $repo,
        private AuditService $audit,
        private BranchContext $branchContext,
        private TenantOwnedDataScopeGuard $tenantScopeGuard
    ) {
    }

    public function getDisplayName(array $staff): string
    {
        return trim($staff['first_name'] . ' ' . $staff['last_name']);
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $userId = $this->currentUserId();
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;
            $data['is_active'] = $data['is_active'] ?? true;
            $id = $this->repo->create($data);
            $this->audit->log('staff_created', 'staff', $id, $userId, $data['branch_id'] ?? null, [
                'staff' => $data,
            ]);
            return $id;
        }, 'staff create');
    }

    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $current = $this->repo->find($id);
            if (!$current) {
                throw new \RuntimeException('Staff not found');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null);
            $userId = $this->currentUserId();
            $data['updated_by'] = $userId;
            if (array_key_exists('is_active', $data)) {
                $data['is_active'] = $data['is_active'] ? 1 : 0;
            }
            $this->repo->update($id, $data);
            $this->audit->log('staff_updated', 'staff', $id, $userId, $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => array_merge($current, $data),
            ]);
        }, 'staff update');
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $staff = $this->repo->find($id);
            if (!$staff) {
                throw new \RuntimeException('Staff not found');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($staff['branch_id'] !== null && $staff['branch_id'] !== '' ? (int) $staff['branch_id'] : null);
            $this->repo->softDelete($id);
            $this->audit->log('staff_deleted', 'staff', $id, $this->currentUserId(), $staff['branch_id'] ?? null, [
                'staff' => $staff,
            ]);
        }, 'staff delete');
    }

    private function currentUserId(): ?int
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
            slog('error', 'staff.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Staff operation failed.');
        }
    }
}
