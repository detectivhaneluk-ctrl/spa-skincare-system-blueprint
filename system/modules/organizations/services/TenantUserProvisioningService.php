<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\App\Database;
use Core\Audit\AuditService;
use Core\Auth\PrincipalAccessService;
use Core\Permissions\PermissionService;
use InvalidArgumentException;

/**
 * Canonical tenant / platform user provisioning (no login-capable “half users”).
 * SUPER-ADMIN-LOGIN-CONTROL-PLANE-CANONICALIZATION-01.
 * FOUNDER-ACCESS-MUTATION-HARDENING-01: transactional writes + audit + principal guards.
 */
final class TenantUserProvisioningService
{
    public function __construct(
        private Database $db,
        private AuditService $audit,
        private PrincipalAccessService $principalAccess,
        private PermissionService $permissions,
    ) {
    }

    public function membershipTableExists(): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['user_organization_memberships']
        );

        return $row !== null;
    }

    /**
     * @param non-empty-string $email
     * @param non-empty-string $password
     * @param non-empty-string $displayName
     */
    public function provisionPlatformFounder(string $email, string $password, string $displayName): int
    {
        $roleId = $this->requireRoleId('platform_founder');
        $email = strtolower(trim($email));

        return $this->db->transaction(function () use ($email, $password, $displayName, $roleId): int {
            return $this->upsertLoginUser($email, $password, $displayName, null, $roleId, true);
        });
    }

    /**
     * @param non-empty-string $email
     * @param non-empty-string $password
     * @param non-empty-string $displayName
     */
    public function provisionTenantAdmin(
        string $email,
        string $password,
        string $displayName,
        int $organizationId,
        int $branchId,
        ?int $actorUserId = null,
    ): int {
        $this->assertBranchInOrganization($branchId, $organizationId);
        $this->assertOrganizationActiveForTenant($organizationId);
        if (!$this->membershipTableExists()) {
            throw new InvalidArgumentException('user_organization_memberships is required for tenant provisioning (migration 087).');
        }
        $roleId = $this->requireRoleId('admin');
        $email = strtolower(trim($email));

        return $this->db->transaction(function () use ($email, $password, $displayName, $branchId, $roleId, $organizationId, $actorUserId): int {
            $userId = $this->upsertLoginUser($email, $password, $displayName, $branchId, $roleId, false);
            $this->ensureMembership($userId, $organizationId, $branchId);
            $this->audit->log(
                'founder_provision_tenant_admin',
                'user',
                $userId,
                $actorUserId,
                null,
                ['email' => $email, 'organization_id' => $organizationId, 'branch_id' => $branchId, 'role' => 'admin']
            );

            return $userId;
        });
    }

    /**
     * @param non-empty-string $email
     * @param non-empty-string $password
     * @param non-empty-string $displayName
     */
    public function provisionTenantStaff(
        string $email,
        string $password,
        string $displayName,
        int $organizationId,
        int $branchId,
        string $roleCode = 'reception',
        ?int $actorUserId = null,
    ): int {
        $this->assertBranchInOrganization($branchId, $organizationId);
        $this->assertOrganizationActiveForTenant($organizationId);
        if (!$this->membershipTableExists()) {
            throw new InvalidArgumentException('user_organization_memberships is required for tenant provisioning (migration 087).');
        }
        $roleId = $this->requireRoleId($roleCode);
        $email = strtolower(trim($email));

        return $this->db->transaction(function () use ($email, $password, $displayName, $branchId, $roleId, $organizationId, $roleCode, $actorUserId): int {
            $userId = $this->upsertLoginUser($email, $password, $displayName, $branchId, $roleId, false);
            $this->ensureMembership($userId, $organizationId, $branchId);
            $this->audit->log(
                'founder_provision_tenant_staff',
                'user',
                $userId,
                $actorUserId,
                null,
                ['email' => $email, 'organization_id' => $organizationId, 'branch_id' => $branchId, 'role' => $roleCode]
            );

            return $userId;
        });
    }

    private function requireRoleId(string $code): int
    {
        $row = $this->db->fetchOne('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL LIMIT 1', [$code]);
        if ($row === null) {
            throw new InvalidArgumentException("Unknown or deleted role code: {$code}");
        }

        return (int) $row['id'];
    }

    private function assertOrganizationActiveForTenant(int $organizationId): void
    {
        if ($organizationId <= 0) {
            throw new InvalidArgumentException('organization_id must be a positive integer.');
        }
        $row = $this->db->fetchOne(
            'SELECT deleted_at, suspended_at FROM organizations WHERE id = ? LIMIT 1',
            [$organizationId]
        );
        if ($row === null) {
            throw new InvalidArgumentException('Organization not found.');
        }
        if (($row['deleted_at'] ?? null) !== null && (string) $row['deleted_at'] !== '') {
            throw new InvalidArgumentException('Cannot provision into a deleted organization.');
        }
        $sat = $row['suspended_at'] ?? null;
        if ($sat !== null && (string) $sat !== '') {
            throw new InvalidArgumentException('Cannot provision into a suspended organization; unsuspend the organization first.');
        }
    }

    private function assertBranchInOrganization(int $branchId, int $organizationId): void
    {
        if ($branchId <= 0 || $organizationId <= 0) {
            throw new InvalidArgumentException('organization_id and branch_id must be positive.');
        }
        $row = $this->db->fetchOne(
            'SELECT 1 FROM branches WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId, $organizationId]
        );
        if ($row === null) {
            throw new InvalidArgumentException('branch_id does not belong to organization_id or branch is not active.');
        }
    }

    /**
     * @param non-empty-string $email
     * @param non-empty-string $password
     * @param non-empty-string $displayName
     */
    private function upsertLoginUser(string $email, string $password, string $displayName, ?int $branchId, int $roleId, bool $platformFounder): int
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }
        if (strlen($email) > 255) {
            throw new InvalidArgumentException('Email is too long.');
        }
        if ($displayName === '') {
            throw new InvalidArgumentException('Display name is required.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $existing = $this->db->fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($existing !== null) {
            $userId = (int) $existing['id'];
            if (!$platformFounder && $this->principalAccess->isPlatformPrincipal($userId)) {
                throw new InvalidArgumentException(
                    'This email belongs to a platform principal. Canonicalize the account before assigning tenant roles, or use a different email.'
                );
            }
            try {
                $this->db->query(
                    'UPDATE users SET password_hash = ?, name = ?, branch_id = ?, deleted_at = NULL, password_changed_at = NOW() WHERE id = ?',
                    [$hash, $displayName, $branchId, $userId]
                );
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'password_changed_at')) {
                    throw $e;
                }
                $this->db->query(
                    'UPDATE users SET password_hash = ?, name = ?, branch_id = ?, deleted_at = NULL WHERE id = ?',
                    [$hash, $displayName, $branchId, $userId]
                );
            }
        } else {
            try {
                $this->db->query(
                    'INSERT INTO users (email, password_hash, name, branch_id, password_changed_at) VALUES (?, ?, ?, ?, NOW())',
                    [$email, $hash, $displayName, $branchId]
                );
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'password_changed_at')) {
                    throw $e;
                }
                $this->db->query(
                    'INSERT INTO users (email, password_hash, name, branch_id) VALUES (?, ?, ?, ?)',
                    [$email, $hash, $displayName, $branchId]
                );
            }
            $userId = (int) $this->db->lastInsertId();
        }

        $this->db->query('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        $this->db->query('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);
        $this->permissions->clearSharedPermissionCacheForStaffUser($userId);

        if ($platformFounder && $this->membershipTableExists()) {
            $this->db->query('DELETE FROM user_organization_memberships WHERE user_id = ?', [$userId]);
        }

        return $userId;
    }

    private function ensureMembership(int $userId, int $organizationId, int $defaultBranchId): void
    {
        $this->db->query(
            'INSERT INTO user_organization_memberships (user_id, organization_id, status, default_branch_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), default_branch_id = VALUES(default_branch_id), updated_at = CURRENT_TIMESTAMP',
            [$userId, $organizationId, 'active', $defaultBranchId]
        );
    }
}
