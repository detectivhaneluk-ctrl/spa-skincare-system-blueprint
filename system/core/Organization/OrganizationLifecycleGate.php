<?php

declare(strict_types=1);

namespace Core\Organization;

use Core\App\Database;

final class OrganizationLifecycleGate
{
    public function __construct(private Database $db)
    {
    }

    public function isOrganizationActive(int $organizationId): bool
    {
        if ($organizationId <= 0) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 FROM organizations WHERE id = ? AND deleted_at IS NULL AND suspended_at IS NULL LIMIT 1',
            [$organizationId]
        );

        return $row !== null;
    }

    public function isBranchLinkedToSuspendedOrganization(int $branchId): bool
    {
        if ($branchId <= 0) {
            return true;
        }
        $row = $this->db->fetchOne(
            'SELECT o.suspended_at AS suspended_at
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id
             WHERE b.id = ? AND b.deleted_at IS NULL AND o.deleted_at IS NULL
             LIMIT 1',
            [$branchId]
        );
        if ($row === null) {
            return true;
        }

        return isset($row['suspended_at']) && $row['suspended_at'] !== null && $row['suspended_at'] !== '';
    }

    public function isTenantUserBoundToSuspendedOrganization(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $branchRow = $this->db->fetchOne(
            'SELECT o.suspended_at AS suspended_at
             FROM users u
             INNER JOIN branches b ON b.id = u.branch_id AND b.deleted_at IS NULL
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
             WHERE u.id = ? AND u.deleted_at IS NULL
             LIMIT 1',
            [$userId]
        );
        if ($branchRow !== null) {
            return isset($branchRow['suspended_at']) && $branchRow['suspended_at'] !== null && $branchRow['suspended_at'] !== '';
        }

        $membershipRow = $this->db->fetchOne(
            'SELECT 1
             FROM user_organization_memberships m
             INNER JOIN organizations o ON o.id = m.organization_id
             WHERE m.user_id = ? AND m.status = ? AND o.deleted_at IS NULL AND o.suspended_at IS NOT NULL
             LIMIT 1',
            [$userId, 'active']
        );

        return $membershipRow !== null;
    }

    /**
     * When the session branch is set, a staff row ties this user to that branch, and staff is inactive:
     * tenant UI requests must fail-closed (inactive operator at location).
     *
     * Returns false when there is no staff row for (user, branch) — e.g. some roles without a staff profile.
     */
    public function isTenantUserInactiveStaffAtBranch(int $userId, int $branchId): bool
    {
        if ($userId <= 0 || $branchId <= 0) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT is_active FROM staff WHERE user_id = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$userId, $branchId]
        );
        if ($row === null) {
            return false;
        }

        return (int) ($row['is_active'] ?? 0) !== 1;
    }
}
