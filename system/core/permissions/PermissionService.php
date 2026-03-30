<?php

declare(strict_types=1);

namespace Core\Permissions;

use Core\App\Database;
use Core\Branch\BranchContext;

final class PermissionService
{
    /** @var array<string, list<string>> keyed by "{userId}:{branch|null}" */
    private array $cache = [];

    public function __construct(
        private Database $db,
        private BranchContext $branchContext,
        private StaffGroupPermissionRepository $staffGroupPermissions
    ) {
    }

    /**
     * Clears merged permission cache (e.g. after staff-group permission pivot changes).
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    public function has(int $userId, string $permission): bool
    {
        $perms = $this->getForUser($userId);
        if (in_array('*', $perms, true)) {
            return true;
        }
        if (in_array($permission, $perms, true)) {
            return true;
        }
        $prefix = explode('.', $permission, 2)[0] ?? '';

        return $prefix !== '' && in_array($prefix . '.*', $perms, true);
    }

    /**
     * Effective codes = role-derived ∪ active staff-group-derived (for current {@see BranchContext} branch).
     *
     * @return list<string>
     */
    public function getForUser(int $userId): array
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        $cacheKey = $userId . ':' . ($branchId === null ? 'null' : (string) $branchId);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }
        $rows = $this->db->fetchAll(
            'SELECT p.code FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             INNER JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
             WHERE ur.user_id = ?',
            [$userId]
        );
        $roleCodes = array_column($rows, 'code');
        $groupCodes = $this->staffGroupPermissions->listPermissionCodesForUserInBranchScope($userId, $branchId);
        $merged = array_values(array_unique(array_merge($roleCodes, $groupCodes)));
        $this->cache[$cacheKey] = $merged;

        return $merged;
    }
}
