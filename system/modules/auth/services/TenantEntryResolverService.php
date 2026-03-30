<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Core\Branch\TenantBranchAccessService;

final class TenantEntryResolverService
{
    public function __construct(private TenantBranchAccessService $tenantBranchAccess)
    {
    }

    /**
     * @return array{state:'single', branch_id:int}|array{state:'multiple', branch_ids:list<int>}|array{state:'none'}
     *
     * Canonical tenant-entry states:
     * - single: exactly one membership-authorized tenant branch
     * - multiple: more than one membership-authorized tenant branch, chooser required
     * - none: fail-closed tenant block (true orphan or unresolved contradiction)
     */
    public function resolveForUser(int $userId): array
    {
        $allowed = $this->tenantBranchAccess->allowedBranchIdsForUser($userId);
        if (count($allowed) === 1) {
            return ['state' => 'single', 'branch_id' => (int) $allowed[0]];
        }
        if (count($allowed) > 1) {
            return ['state' => 'multiple', 'branch_ids' => array_values($allowed)];
        }

        return ['state' => 'none'];
    }
}
