<?php

declare(strict_types=1);

namespace Modules\Staff\Providers;

use Core\Contracts\StaffListProvider;
use Modules\Staff\Repositories\StaffRepository;
use Modules\Staff\Services\StaffGroupService;

final class StaffListProviderImpl implements StaffListProvider
{
    public function __construct(
        private StaffRepository $repo,
        private StaffGroupService $staffGroupService
    ) {
    }

    public function list(?int $branchId = null): array
    {
        $filters = $branchId !== null ? ['branch_id' => $branchId] : [];
        $rows = $this->repo->list($filters, 500, 0);

        return array_values(array_filter($rows, fn (array $r): bool => $this->staffGroupService->isStaffInScopeForBranch((int) $r['id'], $branchId)));
    }
}
