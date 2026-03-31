<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\App\Database;
use Core\Audit\AuditService;
use Core\Auth\PrincipalAccessService;
use Core\Permissions\PermissionService;
use InvalidArgumentException;
use Throwable;

/**
 * Founder-only tenant access mutations (authoritative rows, not smoke shortcuts).
 * SUPER-ADMIN-LOGIN-CONTROL-PLANE-CANONICALIZATION-01.
 * FOUNDER-ACCESS-MUTATION-HARDENING-01: validation, transactions, audit, invariant checks.
 */
final class FounderAccessManagementService
{
    public function __construct(
        private Database $db,
        private AuditService $audit,
        private TenantUserProvisioningService $provisioning,
        private PrincipalAccessService $principalAccess,
        private PermissionService $permissions,
    ) {
    }

    /**
     * @param array<string, mixed>|null $auditExtra merged into audit metadata (operator_reason, effect_summary, etc.)
     */
    public function setUserActive(int $actorUserId, int $targetUserId, bool $active, ?array $auditExtra = null): void
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Invalid actor.');
        }
        if ($targetUserId <= 0) {
            throw new InvalidArgumentException('Invalid user id.');
        }
        if (!$active && $targetUserId === $actorUserId) {
            throw new InvalidArgumentException('You cannot deactivate your own account from this console.');
        }

        $this->db->transaction(function () use ($actorUserId, $targetUserId, $active, $auditExtra): void {
            $user = $this->db->fetchOne(
                'SELECT id, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE',
                [$targetUserId]
            );
            if ($user === null) {
                throw new InvalidArgumentException('User not found.');
            }
            $isDeleted = ($user['deleted_at'] ?? null) !== null && (string) ($user['deleted_at'] ?? '') !== '';
            if ($active) {
                if (!$isDeleted) {
                    return;
                }
                $this->db->query('UPDATE users SET deleted_at = NULL WHERE id = ?', [$targetUserId]);
                $this->audit->log(
                    'founder_user_activated',
                    'user',
                    $targetUserId,
                    $actorUserId,
                    null,
                    array_merge(['target_user_id' => $targetUserId], $auditExtra ?? [])
                );
            } else {
                if ($isDeleted) {
                    return;
                }
                $this->db->query('UPDATE users SET deleted_at = NOW() WHERE id = ?', [$targetUserId]);
                $this->audit->log(
                    'founder_user_deactivated',
                    'user',
                    $targetUserId,
                    $actorUserId,
                    null,
                    array_merge(['target_user_id' => $targetUserId], $auditExtra ?? [])
                );
            }
        });
    }

    public function setMembershipSuspended(int $actorUserId, int $userId, int $organizationId, bool $suspended): void
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Invalid actor.');
        }
        if ($userId <= 0 || $organizationId <= 0) {
            throw new InvalidArgumentException('user_id and organization_id must be positive integers.');
        }
        if (!$this->provisioning->membershipTableExists()) {
            throw new InvalidArgumentException('Membership table not available.');
        }

        $this->db->transaction(function () use ($actorUserId, $userId, $organizationId, $suspended): void {
            $row = $this->db->fetchOne(
                'SELECT m.status AS status, o.deleted_at AS org_deleted_at, o.suspended_at AS org_suspended_at
                 FROM user_organization_memberships m
                 INNER JOIN organizations o ON o.id = m.organization_id
                 WHERE m.user_id = ? AND m.organization_id = ?
                 FOR UPDATE',
                [$userId, $organizationId]
            );
            if ($row === null) {
                throw new InvalidArgumentException('No membership row exists for this user and organization.');
            }
            $current = (string) ($row['status'] ?? '');
            $target = $suspended ? 'suspended' : 'active';
            if ($current === $target) {
                throw new InvalidArgumentException(
                    $suspended ? 'Membership is already suspended.' : 'Membership is already active.'
                );
            }
            if (!$suspended) {
                $od = $row['org_deleted_at'] ?? null;
                if ($od !== null && (string) $od !== '') {
                    throw new InvalidArgumentException('Cannot unsuspend membership: organization is deleted.');
                }
                $sat = $row['org_suspended_at'] ?? null;
                if ($sat !== null && (string) $sat !== '') {
                    throw new InvalidArgumentException(
                        'Cannot unsuspend membership while the organization is suspended; clear organization suspension first.'
                    );
                }
            }

            $this->db->query(
                'UPDATE user_organization_memberships SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND organization_id = ?',
                [$target, $userId, $organizationId]
            );
            $this->audit->log(
                $suspended ? 'founder_membership_suspended' : 'founder_membership_unsuspended',
                'user',
                $userId,
                $actorUserId,
                null,
                ['organization_id' => $organizationId, 'status' => $target]
            );
        });
    }

    /**
     * Pin branch and ensure active membership (does not change password or roles).
     */
    /**
     * @param array<string, mixed>|null $auditExtra merged into audit metadata
     */
    public function repairTenantBranchAndMembership(int $actorUserId, int $userId, int $organizationId, int $branchId, ?array $auditExtra = null): void
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Invalid actor.');
        }
        if ($userId <= 0 || $organizationId <= 0 || $branchId <= 0) {
            throw new InvalidArgumentException('user_id, organization_id, and branch_id must be positive integers.');
        }
        if (!$this->provisioning->membershipTableExists()) {
            throw new InvalidArgumentException('Membership table not available.');
        }
        if ($this->principalAccess->isPlatformPrincipal($userId)) {
            throw new InvalidArgumentException('Refusing to attach tenant membership to a platform principal.');
        }

        $this->db->transaction(function () use ($actorUserId, $userId, $organizationId, $branchId, $auditExtra): void {
            $user = $this->db->fetchOne('SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new InvalidArgumentException('User not found.');
            }

            $org = $this->db->fetchOne(
                'SELECT id, deleted_at, suspended_at FROM organizations WHERE id = ? LIMIT 1 FOR UPDATE',
                [$organizationId]
            );
            if ($org === null) {
                throw new InvalidArgumentException('Organization not found.');
            }
            if (($org['deleted_at'] ?? null) !== null && (string) ($org['deleted_at'] ?? '') !== '') {
                throw new InvalidArgumentException('Cannot repair access against a deleted organization.');
            }
            $sat = $org['suspended_at'] ?? null;
            if ($sat !== null && (string) $sat !== '') {
                throw new InvalidArgumentException(
                    'Cannot repair access while the organization is suspended; unsuspend the organization first.'
                );
            }

            $brow = $this->db->fetchOne(
                'SELECT id FROM branches WHERE id = ? AND organization_id = ? AND deleted_at IS NULL LIMIT 1',
                [$branchId, $organizationId]
            );
            if ($brow === null) {
                throw new InvalidArgumentException('Branch does not exist, is deleted, or does not belong to the given organization.');
            }

            $this->db->query('UPDATE users SET branch_id = ? WHERE id = ?', [$branchId, $userId]);
            $this->db->query(
                'INSERT INTO user_organization_memberships (user_id, organization_id, status, default_branch_id)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), default_branch_id = VALUES(default_branch_id), updated_at = CURRENT_TIMESTAMP',
                [$userId, $organizationId, 'active', $branchId]
            );
            $this->audit->log('founder_tenant_access_repaired', 'user', $userId, $actorUserId, null, array_merge([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
            ], $auditExtra ?? []));
        });
        $this->permissions->clearSharedCacheForUserAllBranchContexts($userId);
    }

    /**
     * Remove tenant-plane roles; keep only platform_founder. Resolves ambiguous dual-plane assignments.
     *
     * @return int number of roles removed
     */
    public function stripNonPlatformRolesFromPlatformPrincipal(int $actorUserId, int $userId): int
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Invalid actor.');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user id.');
        }
        if (!$this->principalAccess->isPlatformPrincipal($userId)) {
            throw new InvalidArgumentException('User is not a platform principal.');
        }
        $pf = $this->db->fetchOne('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL LIMIT 1', ['platform_founder']);
        if ($pf === null) {
            throw new InvalidArgumentException('platform_founder role missing from catalog.');
        }
        $platformRoleId = (int) $pf['id'];

        $removed = (int) $this->db->transaction(function () use ($actorUserId, $userId, $platformRoleId): int {
            $u = $this->db->fetchOne('SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE', [$userId]);
            if ($u === null) {
                throw new InvalidArgumentException('User not found.');
            }

            $stmt = $this->db->query(
                'DELETE ur FROM user_roles ur
                 INNER JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
                 WHERE ur.user_id = ? AND r.code <> ?',
                [$userId, 'platform_founder']
            );
            $removedInner = (int) $stmt->rowCount();
            $this->db->query(
                'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)',
                [$userId, $platformRoleId]
            );
            if ($this->provisioning->membershipTableExists()) {
                $this->db->query('DELETE FROM user_organization_memberships WHERE user_id = ?', [$userId]);
            }
            $this->db->query('UPDATE users SET branch_id = NULL WHERE id = ?', [$userId]);
            $this->audit->log('founder_platform_principal_roles_canonicalized', 'user', $userId, $actorUserId, null, [
                'non_platform_roles_removed' => $removedInner,
            ]);

            return $removedInner;
        });
        $this->permissions->clearSharedCacheForUserAllBranchContexts($userId);

        return $removed;
    }

    /**
     * Founder updates login email (canonical {@code users.email}). Sign-in identifier is case-normalized to lowercase.
     */
    public function updateUserEmailByFounder(int $actorUserId, int $targetUserId, string $newEmail): void
    {
        if ($actorUserId <= 0 || $targetUserId <= 0) {
            throw new InvalidArgumentException('Invalid actor or target.');
        }
        if ($this->principalAccess->isPlatformPrincipal($targetUserId)) {
            throw new InvalidArgumentException('This account cannot be edited from salon admin access.');
        }
        $email = strtolower(trim($newEmail));
        if ($email === '') {
            throw new InvalidArgumentException('Email is required.');
        }
        if (strlen($email) > 255) {
            throw new InvalidArgumentException('Email is too long.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email format is invalid.');
        }

        $this->db->transaction(function () use ($actorUserId, $targetUserId, $email): void {
            $row = $this->db->fetchOne(
                'SELECT id, email FROM users WHERE id = ? LIMIT 1 FOR UPDATE',
                [$targetUserId]
            );
            if ($row === null) {
                throw new InvalidArgumentException('User not found.');
            }
            $old = (string) ($row['email'] ?? '');
            if ($old === $email) {
                return;
            }
            $other = $this->db->fetchOne(
                'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1',
                [$email, $targetUserId]
            );
            if ($other !== null) {
                throw new InvalidArgumentException('Another account already uses this email.');
            }
            $this->db->query('UPDATE users SET email = ? WHERE id = ?', [$email, $targetUserId]);
            $this->audit->log(
                'founder_user_email_updated',
                'user',
                $targetUserId,
                $actorUserId,
                null,
                ['old_email' => $old, 'new_email' => $email]
            );
        });
    }

    /**
     * Founder sets a new password without knowing the current password (tenant admin recovery).
     * Uses the same hashing path as normal password changes.
     */
    public function setUserPasswordByFounder(int $actorUserId, int $targetUserId, string $newPassword): void
    {
        if ($actorUserId <= 0 || $targetUserId <= 0) {
            throw new InvalidArgumentException('Invalid actor or target.');
        }
        if ($this->principalAccess->isPlatformPrincipal($targetUserId)) {
            throw new InvalidArgumentException('This account cannot be edited from salon admin access.');
        }
        $newPassword = trim($newPassword);
        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->transaction(function () use ($actorUserId, $targetUserId, $hash): void {
            $row = $this->db->fetchOne('SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE', [$targetUserId]);
            if ($row === null) {
                throw new InvalidArgumentException('User not found.');
            }
            try {
                $this->db->query(
                    'UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?',
                    [$hash, $targetUserId]
                );
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'password_changed_at')) {
                    throw $e;
                }
                $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $targetUserId]);
            }
            $this->audit->log(
                'founder_user_password_set',
                'user',
                $targetUserId,
                $actorUserId,
                null,
                ['effect' => 'password_hash_rotated_by_founder']
            );
        });
    }
}
