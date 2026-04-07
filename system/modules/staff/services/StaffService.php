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
        if (!empty($staff['display_name'])) {
            return trim((string) $staff['display_name']);
        }
        return trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $userId = $this->currentUserId();
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;
            // Derive is_active from the wizard 'status' field when present; fall back to legacy default.
            if (array_key_exists('status', $data)) {
                $data['is_active'] = ($data['status'] === 'active') ? 1 : 0;
                unset($data['status']);
            } else {
                $data['is_active'] = $data['is_active'] ?? 1;
            }
            // All staff created through the onboarding wizard start at step 1.
            $data['onboarding_step'] = 1;
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

    public function saveStep2(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $current = $this->repo->find($id);
            if (!$current) {
                throw new \RuntimeException('Staff not found.');
            }
            $branchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            $userId = $this->currentUserId();
            $data['updated_by'] = $userId;
            $data['onboarding_step'] = 2;
            $this->repo->update($id, $data);
            $this->audit->log('staff_step2_saved', 'staff', $id, $userId, $branchId, [
                'step' => 2,
                'fields_set' => array_keys($data),
            ]);
        }, 'staff step2 save');
    }

    /**
     * Advance onboarding_step to $step and audit-log the transition.
     * Used from controller save paths that handle domain-specific persistence themselves
     * (e.g. Step 3 service-assignment sync via ServiceRepository before calling this).
     */
    public function advanceOnboardingStep(int $id, int $step): void
    {
        $this->transactional(function () use ($id, $step): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $current = $this->repo->find($id);
            if (!$current) {
                throw new \RuntimeException('Staff not found.');
            }
            $branchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            $userId = $this->currentUserId();
            $this->repo->update($id, ['onboarding_step' => $step, 'updated_by' => $userId]);
            $this->audit->log('staff_onboarding_step_advanced', 'staff', $id, $userId, $branchId, ['step' => $step]);
        }, "staff onboarding step {$step}");
    }

    /**
     * Marks onboarding as complete by setting onboarding_step = NULL.
     * Called after Step 4 (default schedule) is saved successfully.
     */
    public function completeOnboarding(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $current = $this->repo->find($id);
            if (!$current) {
                throw new \RuntimeException('Staff not found.');
            }
            $branchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            $userId = $this->currentUserId();
            $this->repo->update($id, ['onboarding_step' => null, 'updated_by' => $userId]);
            $this->audit->log('staff_onboarding_complete', 'staff', $id, $userId, $branchId, ['step' => 4]);
        }, 'staff onboarding complete');
    }

    /**
     * Move an active staff row to trash (soft delete with retention).
     */
    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $staff = $this->repo->find($id);
            if (!$staff) {
                throw new \RuntimeException('Staff not found');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($staff['branch_id'] !== null && $staff['branch_id'] !== '' ? (int) $staff['branch_id'] : null);
            $purgeAt = $this->purgeAfterMysqlDatetime();
            $n = $this->repo->trash($id, $this->currentUserId(), $purgeAt);
            if ($n !== 1) {
                throw new \DomainException('Could not move this staff member to Trash (they may already be removed).');
            }
            $this->audit->log('staff_trashed', 'staff', $id, $this->currentUserId(), $staff['branch_id'] ?? null, [
                'staff' => $staff,
                'purge_after_at' => $purgeAt,
            ]);
        }, 'staff trash');
    }

    /**
     * @param list<int> $ids
     */
    public function bulkTrash(array $ids): int
    {
        return $this->transactional(function () use ($ids): int {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $purgeAt = $this->purgeAfterMysqlDatetime();

            return $this->repo->bulkTrash($ids, $this->currentUserId(), $purgeAt);
        }, 'staff bulk trash');
    }

    public function restore(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $row = $this->repo->findTrashed($id);
            if (!$row) {
                throw new \DomainException('That staff member was not found in Trash.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null);
            $uid = isset($row['user_id']) && $row['user_id'] !== null && $row['user_id'] !== '' ? (int) $row['user_id'] : null;
            if ($this->repo->existsLiveStaffWithUserIdExcluding($uid, $id)) {
                throw new \DomainException(
                    'Cannot restore: another active staff profile is already linked to the same login user. Unlink or resolve the duplicate first.'
                );
            }
            $n = $this->repo->restore($id);
            if ($n !== 1) {
                throw new \DomainException('Could not restore this staff member.');
            }
            $this->audit->log('staff_restored', 'staff', $id, $this->currentUserId(), $row['branch_id'] ?? null, [
                'staff' => $row,
            ]);
        }, 'staff restore');
    }

    public function permanentlyDelete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $row = $this->repo->findTrashed($id);
            if (!$row) {
                throw new \DomainException('Only trashed staff can be permanently deleted.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null);
            $this->assertHardDeleteDependenciesAllow($id);
            try {
                $n = $this->repo->hardDeleteTrashed($id);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'foreign key')) {
                    throw new \DomainException(
                        'This staff member cannot be permanently deleted because related records still exist.'
                    );
                }
                throw $e;
            }
            if ($n !== 1) {
                throw new \DomainException('Could not permanently delete this staff member.');
            }
            $this->audit->log('staff_permanently_deleted', 'staff', $id, $this->currentUserId(), $row['branch_id'] ?? null, [
                'staff' => $row,
            ]);
        }, 'staff permanent delete');
    }

    /**
     * Cron/CLI: purge expired trashed staff in current tenant scope.
     *
     * @return array{purged: int, skipped_blocked: int, skipped_error: int}
     */
    public function purgeExpiredTrashedBatch(int $batchLimit, ?\DateTimeInterface $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone((string) config('app.timezone', 'UTC')));
        $purged = 0;
        $skippedBlocked = 0;
        $skippedError = 0;
        $ids = $this->repo->listTrashedIdsEligibleForPurge($now, $batchLimit);
        foreach ($ids as $sid) {
            try {
                $this->assertHardDeleteDependenciesAllow($sid);
            } catch (\DomainException) {
                $skippedBlocked++;
                if (function_exists('slog')) {
                    \slog('warning', 'staff.trash_purge', 'skipped_dependencies', ['staff_id' => $sid]);
                }
                continue;
            }
            try {
                $n = $this->repo->hardDeleteTrashed($sid);
                if ($n === 1) {
                    $purged++;
                    $this->audit->log('staff_purged', 'staff', $sid, null, null, ['purged_at' => $now->format(\DateTimeInterface::ATOM)]);
                }
            } catch (\PDOException $e) {
                $skippedError++;
                if (function_exists('slog')) {
                    \slog('warning', 'staff.trash_purge', 'pdo_skip', ['staff_id' => $sid, 'err' => $e->getMessage()]);
                }
            }
        }

        return ['purged' => $purged, 'skipped_blocked' => $skippedBlocked, 'skipped_error' => $skippedError];
    }

    /**
     * @param list<int> $ids
     */
    public function bulkRestore(array $ids): int
    {
        $this->tenantScopeGuard->requireResolvedTenantScope();
        $restored = 0;
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id <= 0) {
                continue;
            }
            try {
                $this->restore($id);
                $restored++;
            } catch (\DomainException | \RuntimeException) {
            }
        }

        return $restored;
    }

    /**
     * @param list<int> $ids
     */
    public function bulkPermanentlyDelete(array $ids): int
    {
        $this->tenantScopeGuard->requireResolvedTenantScope();
        $deleted = 0;
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id <= 0) {
                continue;
            }
            try {
                $this->permanentlyDelete($id);
                $deleted++;
            } catch (\DomainException) {
            }
        }

        return $deleted;
    }

    private function assertHardDeleteDependenciesAllow(int $staffId): void
    {
        if ($this->repo->countAppointmentSeriesForStaff($staffId) > 0) {
            throw new \DomainException(
                'This staff member cannot be permanently deleted because they are linked to appointment series. Cancel or reassign those series first.'
            );
        }
        if ($this->repo->countPayrollCommissionLinesForStaff($staffId) > 0) {
            throw new \DomainException(
                'This staff member cannot be permanently deleted because payroll commission lines still reference them.'
            );
        }
    }

    /**
     * @return non-empty-string
     */
    private function purgeAfterMysqlDatetime(): string
    {
        $days = (int) config('staff.trash_retention_days', 30);
        if ($days < 1) {
            $days = 1;
        }
        $tz = new \DateTimeZone((string) config('app.timezone', 'UTC'));

        return (new \DateTimeImmutable('now', $tz))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
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
