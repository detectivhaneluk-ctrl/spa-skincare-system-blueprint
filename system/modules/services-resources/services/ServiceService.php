<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
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
        private VatRateService $vatRates
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

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $this->tenantScopeGuard->requireResolvedTenantScope();
            $svc = $this->repo->find($id);
            if (!$svc) throw new \RuntimeException('Not found');
            $this->branchContext->assertBranchMatchOrGlobalEntity($svc['branch_id'] !== null && $svc['branch_id'] !== '' ? (int) $svc['branch_id'] : null);
            $this->repo->softDelete($id);
            $this->audit->log('service_deleted', 'service', $id, $this->userId(), $svc['branch_id'] ?? null, [
                'service' => $svc,
            ]);
        }, 'service delete');
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
            throw new \DomainException('Service operation failed.');
        }
    }
}
