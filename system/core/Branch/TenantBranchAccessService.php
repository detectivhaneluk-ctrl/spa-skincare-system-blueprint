<?php

declare(strict_types=1);

namespace Core\Branch;

use Core\App\Database;

/**
 * TENANT-BOUNDARY-HARDENING-01:
 * Canonical allowed-branch resolver for tenant principals.
 *
 * Canonical policy:
 * - Platform principal routing is resolved elsewhere ({@see \Core\Auth\AuthenticatedHomePathResolver}) and must not
 *   be inferred from tenant branch access.
 * - If membership table exists, tenant access truth is membership-aware:
 *   - pinned branch alone is not sufficient
 *   - pinned branch is allowed only when it belongs to at least one active member organization
 *   - no pinned branch falls back to all active branches in active member organizations
 * - If membership table does not exist (legacy installs), pinned branch fallback is used.
 */
final class TenantBranchAccessService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<int>
     */
    public function allowedBranchIdsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $userBranchId = $this->activePinnedBranchIdForUser($userId);
        if ($this->membershipTableExists()) {
            $orgIds = $this->activeMembershipOrganizationIds($userId);
            if ($userBranchId !== null) {
                if ($orgIds === []) {
                    return [];
                }

                return $this->isBranchInOrganizations($userBranchId, $orgIds) ? [$userBranchId] : [];
            }
            if ($orgIds === []) {
                return [];
            }

            return $this->activeBranchIdsByOrganizations($orgIds);
        }

        // Legacy fallback for installs without membership support.
        if ($userBranchId !== null) {
            return $this->legacyPinnedBranchInActiveOrganization($userBranchId) ? [$userBranchId] : [];
        }

        return [];
    }

    public function defaultAllowedBranchIdForUser(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        if ($this->membershipTableExists()) {
            // Keep default resolution consistent with allowedBranchIdsForUser():
            // default is always selected from the already-allowed branch id set.
            $allowed = $this->allowedBranchIdsForUser($userId);
            if ($allowed === []) {
                return null;
            }
            $userBranchId = $this->activePinnedBranchIdForUser($userId);
            if ($userBranchId !== null) {
                return in_array($userBranchId, $allowed, true) ? $userBranchId : null;
            }

            $defaultMembershipBranch = $this->activeDefaultMembershipBranchId($userId);
            if ($defaultMembershipBranch !== null && in_array($defaultMembershipBranch, $allowed, true)) {
                return $defaultMembershipBranch;
            }

            return $allowed[0] ?? null;
        }

        // Legacy fallback for installs without membership support.
        $pinned = $this->activePinnedBranchIdForUser($userId);

        return ($pinned !== null && $this->legacyPinnedBranchInActiveOrganization($pinned)) ? $pinned : null;
    }

    /**
     * @param list<int> $organizationIds
     * @return list<int>
     */
    private function activeBranchIdsByOrganizations(array $organizationIds): array
    {
        $ph = implode(', ', array_fill(0, count($organizationIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT b.id AS id
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL AND o.suspended_at IS NULL
             WHERE b.deleted_at IS NULL AND b.organization_id IN ({$ph})
             ORDER BY b.id ASC",
            $organizationIds
        );
        $ids = [];
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $organizationIds
     */
    private function isBranchInOrganizations(int $branchId, array $organizationIds): bool
    {
        if ($branchId <= 0 || $organizationIds === []) {
            return false;
        }
        $ph = implode(', ', array_fill(0, count($organizationIds), '?'));
        $row = $this->db->fetchOne(
            "SELECT 1 AS ok
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL AND o.suspended_at IS NULL
             WHERE b.id = ? AND b.deleted_at IS NULL AND b.organization_id IN ({$ph})
             LIMIT 1",
            array_merge([$branchId], $organizationIds)
        );

        return $row !== null;
    }

    private function activePinnedBranchIdForUser(int $userId): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT b.id AS id
             FROM users u
             INNER JOIN branches b ON b.id = u.branch_id AND b.deleted_at IS NULL
             WHERE u.id = ? AND u.deleted_at IS NULL
             LIMIT 1',
            [$userId]
        );

        $id = $row !== null && isset($row['id']) ? (int) $row['id'] : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * @return list<int>
     */
    private function activeMembershipOrganizationIds(int $userId): array
    {
        if (!$this->membershipTableExists()) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT m.organization_id AS organization_id
             FROM user_organization_memberships m
             INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL AND o.suspended_at IS NULL
             WHERE m.user_id = ? AND m.status = ?
             ORDER BY m.organization_id ASC',
            [$userId, 'active']
        );
        $ids = [];
        foreach ($rows as $row) {
            $id = isset($row['organization_id']) ? (int) $row['organization_id'] : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function activeDefaultMembershipBranchId(int $userId): ?int
    {
        if (!$this->membershipTableExists()) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT b.id AS id
             FROM user_organization_memberships m
             INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL AND o.suspended_at IS NULL
             INNER JOIN branches b ON b.id = m.default_branch_id AND b.deleted_at IS NULL AND b.organization_id = m.organization_id
             WHERE m.user_id = ? AND m.status = ?
             ORDER BY m.updated_at DESC, m.organization_id ASC
             LIMIT 1',
            [$userId, 'active']
        );
        $id = $row !== null && isset($row['id']) ? (int) $row['id'] : 0;

        return $id > 0 ? $id : null;
    }

    private function membershipTableExists(): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['user_organization_memberships']
        );

        return $row !== null;
    }

    /**
     * Legacy installs: pinned branch must still belong to a non-deleted, non-suspended organization
     * (align with membership-aware paths and {@see \Core\Organization\OrganizationLifecycleGate}).
     */
    private function legacyPinnedBranchInActiveOrganization(int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL AND o.suspended_at IS NULL
             WHERE b.id = ? AND b.deleted_at IS NULL
             LIMIT 1',
            [$branchId]
        );

        return $row !== null;
    }
}
