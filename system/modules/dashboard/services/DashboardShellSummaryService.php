<?php

declare(strict_types=1);

namespace Modules\Dashboard\Services;

use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Dashboard\Repositories\DashboardReadRepository;
use Modules\ServicesResources\Repositories\ServiceRepository;
use Modules\Staff\Repositories\StaffRepository;

/**
 * Read-only home dashboard: entity counts only, aligned with list/index semantics in each module.
 */
final class DashboardShellSummaryService
{
    public function __construct(
        private ClientRepository $clients,
        private StaffRepository $staff,
        private ServiceRepository $services,
        private DashboardReadRepository $reads,
        private BranchContext $branchContext,
        private SessionAuth $session
    ) {
    }

    /**
     * @return array{
     *   counts: array{clients:int, appointments:int, staff:int, services:int},
     *   meta: array{branch_scope_label:string, timezone:string}
     * }
     */
    public function build(): array
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        $staffBranchId = $this->resolveStaffListBranchId($branchId);

        return [
            'counts' => [
                'clients' => $this->clients->count([]),
                'appointments' => $this->reads->countAppointmentsTotal($branchId),
                'staff' => $this->staff->count(array_merge(['active' => true], $staffBranchId !== null ? ['branch_id' => $staffBranchId] : [])),
                'services' => $this->services->count($branchId),
            ],
            'meta' => [
                'branch_scope_label' => $branchId === null ? 'All branches (no branch filter)' : 'Branch ' . $branchId,
                'timezone' => date_default_timezone_get(),
            ],
        ];
    }

    private function resolveStaffListBranchId(?int $branchId): ?int
    {
        if ($branchId !== null) {
            return $branchId;
        }
        $user = $this->session->user();
        if ($user !== null && array_key_exists('branch_id', $user)) {
            $ub = $user['branch_id'];
            if ($ub !== null && $ub !== '') {
                return (int) $ub;
            }
        }

        return null;
    }
}
