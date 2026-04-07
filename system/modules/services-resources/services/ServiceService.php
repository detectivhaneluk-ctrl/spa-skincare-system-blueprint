<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;
use Core\Tenant\TenantOwnedDataScopeGuard;
use Modules\Sales\Services\VatRateService;
use Modules\ServicesResources\Repositories\ServiceRepository;
use Modules\Staff\Repositories\StaffGroupRepository;

final class ServiceService
{
    public function __construct(
        private ServiceRepository $repo,
        private AuditService $audit,
        private BranchContext $branchContext,
        private TenantOwnedDataScopeGuard $tenantScopeGuard,
        private StaffGroupRepository $staffGroups,
        private VatRateService $vatRates,
        private RequestContextHolder $contextHolder,
        private AuthorizerInterface $authorizer,
    ) {
    }

    /**
     * @param list<mixed> $rawFromRequest
     */
    public function validateStaffGroupIdsForService(?int $serviceBranchId, array $rawFromRequest): ?string
    {
        try {
            $ids = $this->normalizeStaffGroupIdsStrict($rawFromRequest);
            $this->staffGroups->assertIdsAssignableToService($serviceBranchId, $ids);

            return null;
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::SERVICE_MANAGE, ResourceRef::collection('service'));
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $rawGroups = $data['staff_group_ids'] ?? [];
            if (!is_array($rawGroups)) {
                throw new \DomainException('Staff groups must be submitted as an array.');
            }
            unset($data['staff_group_ids']);
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $serviceBranch = $this->branchIdFromRow($data);
            $this->vatRates->assertActiveVatRateAssignableToServiceBranch(
                $this->normalizedNullableVatRateId($data['vat_rate_id'] ?? null),
                $serviceBranch
            );
            $this->assertSkuNotDuplicate(null, $data['sku'] ?? null);
            $groupIds = $this->normalizeStaffGroupIdsStrict($rawGroups);
            $this->staffGroups->assertIdsAssignableToService($serviceBranch, $groupIds);
            $data['staff_group_ids'] = $groupIds;
            $userId = $this->userId();
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;
            $id = $this->repo->create($data);
            $this->audit->log('service_created', 'service', $id, $userId, $data['branch_id'] ?? null, [
                'service' => $data,
            ]);
            if ($groupIds !== []) {
                $this->audit->log('service_staff_groups_set', 'service', $id, $userId, $data['branch_id'] ?? null, [
                    'staff_group_ids' => $groupIds,
                ]);
            }

            return $id;
        }, 'service create');
    }

    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::SERVICE_MANAGE, ResourceRef::instance('service', $id));
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $current = $this->repo->find($id);
            if (!$current) throw new \RuntimeException('Not found');
            $this->branchContext->assertBranchMatchOrGlobalEntity($current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null);
            $payload = $data;
            // Post-merge branch_id (and vat_rate_id) from payload over current — authoritative for VAT and staff_group_ids checks.
            $mergedForVat = $current;
            foreach (['branch_id', 'vat_rate_id'] as $k) {
                if (array_key_exists($k, $payload)) {
                    $mergedForVat[$k] = $payload[$k];
                }
            }
            $this->vatRates->assertActiveVatRateAssignableToServiceBranch(
                $this->normalizedNullableVatRateId($mergedForVat['vat_rate_id'] ?? null),
                $this->branchIdFromRow($mergedForVat)
            );
            $this->assertSkuNotDuplicate($id, $payload['sku'] ?? $current['sku'] ?? null);
            $beforeGroups = array_values(array_unique(array_map('intval', $current['staff_group_ids'] ?? [])));
            sort($beforeGroups);
            $newBranch = $this->branchIdFromRow($mergedForVat);
            $oldBranch = $this->branchIdFromRow($current);
            if (!array_key_exists('staff_group_ids', $payload)
                && array_key_exists('branch_id', $payload)
                && $oldBranch !== $newBranch) {
                $valid = $this->staffGroups->filterIdsAssignableToServiceBranch($newBranch, $beforeGroups);
                sort($valid);
                if ($beforeGroups !== $valid) {
                    $payload['staff_group_ids'] = $valid;
                }
            }
            if (array_key_exists('staff_group_ids', $payload)) {
                $raw = $payload['staff_group_ids'];
                if (!is_array($raw)) {
                    throw new \DomainException('Staff groups must be submitted as an array.');
                }
                $groupIds = $this->normalizeStaffGroupIdsStrict($raw);
                $this->staffGroups->assertIdsAssignableToService($this->branchIdFromRow($mergedForVat), $groupIds);
                $payload['staff_group_ids'] = $groupIds;
            }
            $payload['updated_by'] = $this->userId();
            $this->repo->update($id, $payload);
            $userId = $this->userId();
            $this->audit->log('service_updated', 'service', $id, $userId, $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => array_merge($current, $payload),
            ]);
            if (array_key_exists('staff_group_ids', $payload)) {
                $after = $payload['staff_group_ids'];
                sort($after);
                if ($beforeGroups !== $after) {
                    $this->audit->log('service_staff_groups_replaced', 'service', $id, $userId, $current['branch_id'] ?? null, [
                        'before_staff_group_ids' => $beforeGroups,
                        'after_staff_group_ids' => $after,
                    ]);
                }
            }
        }, 'service update');
    }

    /**
     * Move a live service to trash (soft delete with retention).
     */
    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::SERVICE_MANAGE, ResourceRef::instance('service', $id));
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $svc = $this->repo->find($id);
            if (!$svc) {
                throw new \RuntimeException('Not found');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($svc['branch_id'] !== null && $svc['branch_id'] !== '' ? (int) $svc['branch_id'] : null);
            $purgeAt = $this->purgeAfterMysqlDatetime();
            $n = $this->repo->trash($id, $this->userId(), $purgeAt);
            if ($n !== 1) {
                throw new \DomainException('Could not move this service to trash (it may have already been removed).');
            }
            $this->audit->log('service_trashed', 'service', $id, $this->userId(), $svc['branch_id'] ?? null, [
                'service' => $svc,
                'purge_after_at' => $purgeAt,
            ]);
        }, 'service trash');
    }

    /**
     * Bulk move visible/active services to trash (tenant-scoped via repository WHERE).
     *
     * @param list<int> $ids
     */
    public function bulkTrash(array $ids): int
    {
        return $this->transactional(function () use ($ids): int {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::SERVICE_MANAGE, ResourceRef::collection('service'));
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $purgeAt = $this->purgeAfterMysqlDatetime();

            return $this->repo->bulkTrash($ids, $this->userId(), $purgeAt);
        }, 'service bulk trash');
    }

    /**
     * Restore a trashed service. Fails with {@see \DomainException} on SKU conflict with another live service.
     */
    public function restore(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::SERVICE_MANAGE, ResourceRef::instance('service', $id));
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $svc = $this->repo->findTrashed($id);
            if (!$svc) {
                throw new \DomainException('That service was not found in Trash.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($svc['branch_id'] !== null && $svc['branch_id'] !== '' ? (int) $svc['branch_id'] : null);
            $this->assertSkuNotDuplicate($id, $svc['sku'] ?? null);
            $n = $this->repo->restore($id);
            if ($n !== 1) {
                throw new \DomainException('Could not restore this service.');
            }
            $this->audit->log('service_restored', 'service', $id, $this->userId(), $svc['branch_id'] ?? null, [
                'service' => $svc,
            ]);
        }, 'service restore');
    }

    /**
     * Permanently delete a trashed service (operator action from Trash only in UI).
     */
    public function permanentlyDelete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::SERVICE_MANAGE, ResourceRef::instance('service', $id));
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $svc = $this->repo->findTrashed($id);
            if (!$svc) {
                throw new \DomainException('Only trashed services can be permanently deleted.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($svc['branch_id'] !== null && $svc['branch_id'] !== '' ? (int) $svc['branch_id'] : null);
            if ($this->repo->countAppointmentSeriesForService($id) > 0) {
                throw new \DomainException(
                    'This service cannot be permanently deleted because appointment series still reference it. Remove or reassign those series first.'
                );
            }
            try {
                $n = $this->repo->hardDeleteTrashed($id);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'foreign key')) {
                    throw new \DomainException(
                        'This service cannot be permanently deleted because related records still exist.'
                    );
                }
                throw $e;
            }
            if ($n !== 1) {
                throw new \DomainException(
                    'This service cannot be permanently deleted because related records still exist.'
                );
            }
            $this->audit->log('service_permanently_deleted', 'service', $id, $this->userId(), $svc['branch_id'] ?? null, [
                'service' => $svc,
            ]);
        }, 'service permanent delete');
    }

    /**
     * Cron/CLI only: purge trashed services past retention in the **current** resolved tenant scope.
     * Caller must set organization (+ branch) context like other tenant CLI scripts.
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
            if ($this->repo->countAppointmentSeriesForService($sid) > 0) {
                $skippedBlocked++;
                if (function_exists('slog')) {
                    \slog('warning', 'services.trash_purge', 'skipped_appointment_series', ['service_id' => $sid]);
                }
                continue;
            }
            try {
                $n = $this->repo->hardDeleteTrashed($sid);
                if ($n === 1) {
                    $purged++;
                    $this->audit->log('service_purged', 'service', $sid, null, null, ['purged_at' => $now->format(\DateTimeInterface::ATOM)]);
                }
            } catch (\PDOException $e) {
                $skippedError++;
                if (function_exists('slog')) {
                    \slog('warning', 'services.trash_purge', 'pdo_skip', ['service_id' => $sid, 'err' => $e->getMessage()]);
                }
            }
        }

        return ['purged' => $purged, 'skipped_blocked' => $skippedBlocked, 'skipped_error' => $skippedError];
    }

    /**
     * @return non-empty-string MySQL datetime for purge_after_at
     */
    private function purgeAfterMysqlDatetime(): string
    {
        $days = (int) config('services.trash_retention_days', 30);
        if ($days < 1) {
            $days = 1;
        }
        $tz = new \DateTimeZone((string) config('app.timezone', 'UTC'));

        return (new \DateTimeImmutable('now', $tz))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    }

    /**
     * @param list<int> $ids
     */
    public function bulkRestore(array $ids): int
    {
        $ctx = $this->contextHolder->requireContext();
        $ctx->requireResolvedTenant();
        $this->authorizer->requireAuthorized($ctx, ResourceAction::SERVICE_MANAGE, ResourceRef::collection('service'));
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
                // Per-row outcome; continue (SKU conflict, not found, etc.)
            }
        }

        return $restored;
    }

    /**
     * @param list<int|string> $ids
     * @return array{deleted: int, blocked: list<array{id: int, label: string, reason: string}>}
     */
    public function bulkPermanentlyDelete(array $ids): array
    {
        $ctx = $this->contextHolder->requireContext();
        $ctx->requireResolvedTenant();
        $this->authorizer->requireAuthorized($ctx, ResourceAction::SERVICE_MANAGE, ResourceRef::collection('service'));
        $this->tenantScopeGuard->requireResolvedTenantScope();
        $deleted = 0;
        $blocked = [];
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id <= 0) {
                continue;
            }
            try {
                $this->permanentlyDelete($id);
                $deleted++;
            } catch (\DomainException $e) {
                $blocked[] = [
                    'id' => $id,
                    'label' => $this->serviceLabelForBulkOutcome($id),
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return ['deleted' => $deleted, 'blocked' => $blocked];
    }

    /**
     * Throws DomainException if another live service already uses this SKU.
     * $excludeId = null on create, the current service id on update.
     */
    private function assertSkuNotDuplicate(?int $excludeId, mixed $sku): void
    {
        if ($sku === null || $sku === '') {
            return;
        }
        $existing = $this->repo->findBySkuExcluding((string) $sku, $excludeId);
        if ($existing !== null) {
            throw new \DomainException('SKU "' . $sku . '" is already used by another service.');
        }
    }

    private function userId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function branchIdFromRow(array $row): ?int
    {
        if (!isset($row['branch_id']) || $row['branch_id'] === '' || $row['branch_id'] === null) {
            return null;
        }

        return (int) $row['branch_id'];
    }

    private function normalizedNullableVatRateId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return null;
        }
        $id = is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @param list<mixed> $raw
     * @return list<int>
     */
    private function normalizeStaffGroupIdsStrict(array $raw): array
    {
        $seen = [];
        $out = [];
        foreach ($raw as $v) {
            if (is_array($v) || is_object($v)) {
                throw new \DomainException('Staff groups must be submitted as an array of integer ids.');
            }
            if (is_bool($v)) {
                throw new \DomainException('Invalid staff group id.');
            }
            if (is_int($v)) {
                $id = $v;
            } elseif (is_string($v) && $v !== '' && preg_match('/^-?\d+$/', $v) === 1) {
                $id = (int) $v;
            } else {
                throw new \DomainException('Invalid staff group id.');
            }
            if ($id <= 0) {
                throw new \DomainException('Staff group ids must be positive integers.');
            }
            if (isset($seen[$id])) {
                throw new \DomainException('Duplicate staff group id in submission.');
            }
            $seen[$id] = true;
            $out[] = $id;
        }

        return $out;
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
            slog('error', 'services_resources.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            if ($action === 'service permanent delete') {
                throw new \DomainException(
                    'This service cannot be permanently deleted because related records still exist.',
                    0,
                    $e
                );
            }
            throw new \DomainException('Service operation failed.');
        }
    }

    private function serviceLabelForBulkOutcome(int $id): string
    {
        $row = $this->repo->findTrashed($id) ?? $this->repo->find($id);
        if ($row === null) {
            return '#' . $id;
        }
        $name = trim((string) ($row['name'] ?? ''));

        return $name !== '' ? ('#' . $id . ' ' . $name) : ('#' . $id);
    }
}
