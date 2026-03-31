<?php

declare(strict_types=1);

namespace Core\Permissions;

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Contracts\SharedCacheInterface;

final class PermissionService
{
    private const CACHE_TTL_SECONDS = 120;
    private const CACHE_KEY_PREFIX = 'perm_v1';

    /** @var array<string, list<string>> per-request in-memory cache keyed by "{userId}:{branch|null}" */
    private array $cache = [];

    public function __construct(
        private Database $db,
        private BranchContext $branchContext,
        private StaffGroupPermissionRepository $staffGroupPermissions,
        private SharedCacheInterface $sharedCache,
    ) {
    }

    /**
     * Clears in-process cache (e.g. after staff-group permission pivot changes within the same request).
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Invalidates the cross-request shared cache for a specific user+branch.
     * Call this after any role assignment, permission grant, or staff-group membership change for the user.
     */
    public function clearCachedForUser(int $userId, ?int $branchId): void
    {
        $sharedKey = $this->sharedCacheKey($userId, $branchId);
        try {
            $this->sharedCache->delete($sharedKey);
        } catch (\Throwable) {
            // Fail-open: if the cache backend is unavailable, the TTL will expire the entry naturally.
        }
        // Also clear the local in-process entry.
        $localKey = $userId . ':' . ($branchId === null ? 'null' : (string) $branchId);
        unset($this->cache[$localKey]);
    }

    /**
     * Invalidates every shared-cache key this user may have for the current tenant (null branch + each plausible branch context).
     * Use after role assignment changes, membership/branch repair, or any event where a single-branch {@see clearCachedForUser} is insufficient.
     */
    public function clearSharedCacheForUserAllBranchContexts(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $this->clearCachedForUser($userId, null);
        foreach ($this->resolveBranchIdsForPermissionCacheInvalidation($userId) as $bid) {
            $this->clearCachedForUser($userId, $bid);
        }
    }

    /**
     * After staff-group permission pivot changes or group membership changes, drop shared permission cache for every login user linked to members.
     */
    public function invalidateStaffGroupPermissionCachesForGroupMembers(int $staffGroupId): void
    {
        if ($staffGroupId <= 0) {
            return;
        }
        try {
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT st.user_id FROM staff_group_members sgm
                 INNER JOIN staff st ON st.id = sgm.staff_id
                 WHERE sgm.staff_group_id = ? AND st.user_id IS NOT NULL AND st.deleted_at IS NULL',
                [$staffGroupId]
            );
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '42S02') {
                return;
            }
            throw $e;
        }
        foreach ($rows as $r) {
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid > 0) {
                $this->clearSharedCacheForUserAllBranchContexts($uid);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function resolveBranchIdsForPermissionCacheInvalidation(int $userId): array
    {
        $ids = [];
        $u = $this->db->fetchOne('SELECT branch_id FROM users WHERE id = ?', [$userId]);
        if ($u !== null && isset($u['branch_id']) && $u['branch_id'] !== null && (int) $u['branch_id'] > 0) {
            $ids[(int) $u['branch_id']] = true;
        }
        try {
            foreach (
                $this->db->fetchAll(
                    'SELECT DISTINCT branch_id FROM staff WHERE user_id = ? AND deleted_at IS NULL AND branch_id IS NOT NULL',
                    [$userId]
                ) as $r
            ) {
                $bid = (int) ($r['branch_id'] ?? 0);
                if ($bid > 0) {
                    $ids[$bid] = true;
                }
            }
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') !== '42S02') {
                throw $e;
            }
        }
        try {
            foreach (
                $this->db->fetchAll(
                    'SELECT DISTINCT b.id AS id FROM branches b
                     INNER JOIN user_organization_memberships uom ON uom.organization_id = b.organization_id
                     WHERE uom.user_id = ? AND uom.status = ? AND b.deleted_at IS NULL',
                    [$userId, 'active']
                ) as $r
            ) {
                $bid = (int) ($r['id'] ?? 0);
                if ($bid > 0) {
                    $ids[$bid] = true;
                }
            }
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') !== '42S02') {
                throw $e;
            }
        }

        return array_map(static fn (int $id): int => $id, array_keys($ids));
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
     * Results are cached in-process for the lifetime of the request and cross-request in SharedCache (TTL 120s).
     *
     * @return list<string>
     */
    public function getForUser(int $userId): array
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        $localKey = $userId . ':' . ($branchId === null ? 'null' : (string) $branchId);

        // 1. In-process cache (same request, zero cost).
        if (array_key_exists($localKey, $this->cache)) {
            return $this->cache[$localKey];
        }

        // 2. Cross-request shared cache (Redis when available, Noop fallback — always fail-open).
        $sharedKey = $this->sharedCacheKey($userId, $branchId);
        try {
            $cached = $this->sharedCache->get($sharedKey);
            if ($cached !== null) {
                /** @var list<string> $decoded */
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    $this->cache[$localKey] = $decoded;
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // Fail-open: if SharedCache throws, proceed to DB.
        }

        // 3. DB fallback — always authoritative.
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
        $this->cache[$localKey] = $merged;

        try {
            $this->sharedCache->set($sharedKey, (string) json_encode($merged, JSON_UNESCAPED_SLASHES), self::CACHE_TTL_SECONDS);
        } catch (\Throwable) {
            // Fail-open: if SharedCache throws, local in-process cache still protects within-request.
        }

        return $merged;
    }

    private function sharedCacheKey(int $userId, ?int $branchId): string
    {
        $b = $branchId === null ? 'null' : (string) $branchId;
        return self::CACHE_KEY_PREFIX . ':u' . $userId . ':b' . $b;
    }
}
