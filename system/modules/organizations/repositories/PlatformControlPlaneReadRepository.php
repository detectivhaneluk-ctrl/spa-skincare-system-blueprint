<?php

declare(strict_types=1);

namespace Modules\Organizations\Repositories;

use Core\App\Database;

/**
 * Global / cross-tenant read aggregates for the platform control plane only.
 * Not branch- or organization-context filtered.
 */
final class PlatformControlPlaneReadRepository
{
    public function __construct(private Database $db)
    {
    }

    public function countActiveOrganizations(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM organizations WHERE deleted_at IS NULL'
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countSuspendedOrganizations(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM organizations WHERE deleted_at IS NULL AND suspended_at IS NOT NULL'
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countActiveBranches(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM branches WHERE deleted_at IS NULL'
        );

        return (int) ($row['c'] ?? 0);
    }

    /** Staff login accounts (users table). */
    public function countActiveUsers(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM users WHERE deleted_at IS NULL'
        );

        return (int) ($row['c'] ?? 0);
    }

    /** Staff directory rows linked to users. */
    public function countActiveStaffProfiles(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM staff WHERE deleted_at IS NULL'
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countNonDeletedAppointments(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM appointments WHERE deleted_at IS NULL'
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countNonDeletedClients(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM clients WHERE deleted_at IS NULL'
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<array{id:int|string,name:string,created_at:string}>
     */
    public function listRecentOrganizations(int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $sql = 'SELECT id, name, created_at FROM organizations
                WHERE deleted_at IS NULL
                ORDER BY created_at DESC, id DESC
                LIMIT ' . $limit;

        return $this->db->fetchAll($sql);
    }

    /** Active branches whose organization is suspended (operational risk indicator). */
    public function countBranchesUnderSuspendedOrganizations(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
             WHERE b.deleted_at IS NULL AND o.suspended_at IS NOT NULL'
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Non-deleted branches whose organization row is soft-deleted (integrity anomaly).
     */
    public function countActiveBranchesLinkedToDeletedOrganizations(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id
             WHERE b.deleted_at IS NULL AND o.deleted_at IS NOT NULL'
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Keyset pagination of active login accounts for full-population access-shape aggregation.
     *
     * @return list<int>
     */
    public function listActiveUserIdsAfterId(int $afterId, int $limit): array
    {
        $afterId = max(0, $afterId);
        $limit = max(1, min(500, $limit));
        $rows = $this->db->fetchAll(
            'SELECT id FROM users WHERE deleted_at IS NULL AND id > ? ORDER BY id ASC LIMIT ' . $limit,
            [$afterId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) ($r['id'] ?? 0);
        }

        return $out;
    }

    public function countNonDeletedBranchesForOrganization(int $organizationId): int
    {
        if ($organizationId <= 0) {
            return 0;
        }
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM branches WHERE deleted_at IS NULL AND organization_id = ?',
            [$organizationId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Active login accounts with at least one active membership on this organization (0 if memberships table missing).
     */
    public function countActiveUsersWithActiveMembershipOnOrganization(int $organizationId): int
    {
        if ($organizationId <= 0) {
            return 0;
        }
        if ($this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['user_organization_memberships']
        ) === null) {
            return $this->countActiveUsersPinnedToOrganizationBranches($organizationId);
        }
        $row = $this->db->fetchOne(
            'SELECT COUNT(DISTINCT m.user_id) AS c
             FROM user_organization_memberships m
             INNER JOIN users u ON u.id = m.user_id AND u.deleted_at IS NULL
             WHERE m.organization_id = ? AND m.status = ?',
            [$organizationId, 'active']
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Fallback when membership pivot is absent: users pinned to a branch belonging to the organization.
     */
    public function countActiveUsersPinnedToOrganizationBranches(int $organizationId): int
    {
        if ($organizationId <= 0) {
            return 0;
        }
        $row = $this->db->fetchOne(
            'SELECT COUNT(DISTINCT u.id) AS c
             FROM users u
             INNER JOIN branches b ON b.id = u.branch_id AND b.deleted_at IS NULL AND b.organization_id = ?
             WHERE u.deleted_at IS NULL',
            [$organizationId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Distinct active users pinned to this branch or using it as default branch on an active membership.
     */
    /**
     * If the branch is pinned and its organization is suspended, return that organization id (otherwise null).
     */
    public function findSuspendedOrganizationIdForBranch(int $branchId): ?int
    {
        if ($branchId <= 0) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT o.id AS id FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
             WHERE b.id = ? AND b.deleted_at IS NULL AND o.suspended_at IS NOT NULL
             LIMIT 1',
            [$branchId]
        );
        if ($row === null) {
            return null;
        }
        $id = (int) ($row['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    public function countDistinctActiveUsersLinkedToBranch(int $branchId): int
    {
        if ($branchId <= 0) {
            return 0;
        }
        $mem = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['user_organization_memberships']
        ) !== null;
        if (!$mem) {
            $row = $this->db->fetchOne(
                'SELECT COUNT(*) AS c FROM users WHERE deleted_at IS NULL AND branch_id = ?',
                [$branchId]
            );

            return (int) ($row['c'] ?? 0);
        }
        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT uid) AS c FROM (
                SELECT u.id AS uid FROM users u WHERE u.deleted_at IS NULL AND u.branch_id = ?
                UNION
                SELECT m.user_id AS uid FROM user_organization_memberships m
                WHERE m.default_branch_id = ? AND m.status = 'active'
            ) t",
            [$branchId, $branchId]
        );

        return (int) ($row['c'] ?? 0);
    }
}
