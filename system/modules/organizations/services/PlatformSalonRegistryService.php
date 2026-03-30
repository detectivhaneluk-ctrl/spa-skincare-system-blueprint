<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository;

/**
 * Salon registry list read model for /platform-admin/salons.
 */
final class PlatformSalonRegistryService
{
    private const PLAN_PLACEHOLDER = '—';

    public function __construct(
        private PlatformSalonRegistryReadRepository $salonReads,
        private PlatformSalonProblemsService $problems
    ) {
    }

    /**
     * @return array{salons: list<array<string, mixed>>}
     */
    public function buildList(?string $q, string $lifecycleFilter, bool $problemsOnly, bool $canManage): array
    {
        $rows = $this->salonReads->listOrganizationsFiltered($q, $lifecycleFilter);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) ($r['id'] ?? 0);
        }
        $branchCounts = $this->salonReads->countBranchesByOrganizationIds($ids);
        $admins = $this->salonReads->batchPrimaryAdminForOrganizations($ids);

        $salons = [];
        foreach ($rows as $org) {
            $oid = (int) ($org['id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $bc = (int) ($branchCounts[$oid] ?? 0);
            $admin = $admins[$oid] ?? null;
            $pc = $this->problems->countProblems($org, $bc, $admin, null, $oid);
            if ($problemsOnly && $pc < 1) {
                continue;
            }
            $lifecycle = $this->lifecycleLabel($org);
            $salons[] = [
                'id' => $oid,
                'name' => (string) ($org['name'] ?? ''),
                'code' => $org['code'] !== null && $org['code'] !== '' ? (string) $org['code'] : null,
                'lifecycle_status' => $lifecycle,
                'primary_admin_email' => $admin !== null ? (string) ($admin['email'] ?? '') : null,
                'branch_count' => $bc,
                'problem_count' => $pc,
                'plan_summary' => self::PLAN_PLACEHOLDER,
                'updated_at' => (string) ($org['updated_at'] ?? ''),
                'available_actions' => $this->listActions($org, $canManage),
            ];
        }

        return ['salons' => $salons];
    }

    /**
     * @param array<string, mixed> $org
     * @return list<array{key:string, label:string, url:string}>
     */
    private function listActions(array $org, bool $canManage): array
    {
        $id = (int) ($org['id'] ?? 0);
        $actions = [
            ['key' => 'open', 'label' => 'Open', 'url' => '/platform-admin/salons/' . $id],
        ];
        if ($canManage && empty($org['deleted_at'])) {
            $actions[] = ['key' => 'edit', 'label' => 'Edit', 'url' => '/platform-admin/salons/' . $id . '/edit'];
        }
        $deleted = !empty($org['deleted_at']);
        $suspended = !$deleted && !empty($org['suspended_at']);
        if ($canManage && !$deleted && !$suspended) {
            $actions[] = [
                'key' => 'suspend',
                'label' => 'Suspend',
                'url' => '/platform-admin/salons/' . $id . '/suspend-confirm',
            ];
        }
        if ($canManage && $suspended) {
            $actions[] = [
                'key' => 'reactivate',
                'label' => 'Reactivate',
                'url' => '/platform-admin/salons/' . $id . '/reactivate-confirm',
            ];
        }
        if ($canManage && !$deleted) {
            $actions[] = [
                'key' => 'archive',
                'label' => 'Archive',
                'url' => '/platform-admin/salons/' . $id . '/archive-confirm',
            ];
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $org
     */
    private function lifecycleLabel(array $org): string
    {
        if (!empty($org['deleted_at'])) {
            return 'archived';
        }
        if (!empty($org['suspended_at'])) {
            return 'suspended';
        }

        return 'active';
    }
}
