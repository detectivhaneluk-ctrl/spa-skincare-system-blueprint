<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\App\Database;
use Core\Audit\AuditService;
use InvalidArgumentException;

/**
 * Founder control-plane branch catalog (global, cross-tenant). Tenant branch admin stays under /branches + org context.
 * Aligns with {@see \Core\Branch\BranchDirectory} rules (soft delete, code shape); this service is the explicit platform entry.
 * PLATFORM-GLOBAL-BRANCH-CONTROL-WIRING-01.
 */
final class PlatformGlobalBranchManagementService
{
    public function __construct(
        private Database $db,
        private AuditService $audit,
    ) {
    }

    /**
     * @return list<array{id:int,name:string,code:?string,deleted_at:?string,organization_id:int,organization_name:string,org_deleted_at:?string,org_suspended_at:?string}>
     */
    public function listBranchesWithOrganizations(): array
    {
        return $this->db->fetchAll(
            'SELECT b.id, b.name, b.code, b.deleted_at, b.organization_id,
                    o.name AS organization_name, o.deleted_at AS org_deleted_at, o.suspended_at AS org_suspended_at
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id
             ORDER BY o.name ASC, b.name ASC, b.id ASC'
        );
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function listOrganizationsForBranchForm(): array
    {
        return $this->db->fetchAll(
            'SELECT id, name FROM organizations WHERE deleted_at IS NULL ORDER BY name ASC, id ASC LIMIT 500'
        );
    }

    /**
     * @return array{id:int,name:string,code:?string,deleted_at:?string,organization_id:int,organization_name:string,org_deleted_at:?string,org_suspended_at:?string}|null
     */
    public function getBranchWithOrganization(int $branchId): ?array
    {
        if ($branchId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT b.id, b.name, b.code, b.deleted_at, b.organization_id,
                    o.name AS organization_name, o.deleted_at AS org_deleted_at, o.suspended_at AS org_suspended_at
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id
             WHERE b.id = ?
             LIMIT 1',
            [$branchId]
        );
    }

    public function createBranch(int $actorUserId, int $organizationId, string $name, ?string $code): int
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Invalid actor.');
        }
        $this->assertOrganizationAcceptsBranches($organizationId);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Branch name is required.');
        }
        if (strlen($name) > 255) {
            throw new InvalidArgumentException('Branch name is too long.');
        }
        $code = $this->normalizeCode($code);
        if ($code !== null && $this->isCodeTakenGlobally($code, null)) {
            throw new InvalidArgumentException('That branch code is already in use.');
        }

        return (int) $this->db->transaction(function () use ($actorUserId, $organizationId, $name, $code): int {
            $id = $this->db->insert('branches', [
                'name' => $name,
                'code' => $code,
                'organization_id' => $organizationId,
            ]);
            $this->audit->log('founder_branch_created', 'branch', $id, $actorUserId, null, [
                'organization_id' => $organizationId,
                'name' => $name,
                'code' => $code,
            ]);

            return $id;
        });
    }

    public function updateBranch(int $actorUserId, int $branchId, string $name, ?string $code): void
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Invalid actor.');
        }
        if ($branchId <= 0) {
            throw new InvalidArgumentException('Invalid branch id.');
        }

        $this->db->transaction(function () use ($actorUserId, $branchId, $name, $code): void {
            $before = $this->db->fetchOne(
                'SELECT b.id, b.name, b.code, b.deleted_at, b.organization_id,
                        o.name AS organization_name
                 FROM branches b
                 INNER JOIN organizations o ON o.id = b.organization_id
                 WHERE b.id = ?
                 FOR UPDATE',
                [$branchId]
            );
            if ($before === null) {
                throw new InvalidArgumentException('Branch not found.');
            }

            $name = trim($name);
            if ($name === '') {
                throw new InvalidArgumentException('Branch name is required.');
            }
            if (strlen($name) > 255) {
                throw new InvalidArgumentException('Branch name is too long.');
            }
            $code = $this->normalizeCode($code);
            if ($code !== null && $this->isCodeTakenGlobally($code, $branchId)) {
                throw new InvalidArgumentException('That branch code is already in use.');
            }

            $orgId = (int) $before['organization_id'];
            $this->db->query(
                'UPDATE branches SET name = ?, code = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND organization_id = ?',
                [$name, $code, $branchId, $orgId]
            );

            $after = $this->getBranchWithOrganization($branchId);
            $this->audit->log('founder_branch_updated', 'branch', $branchId, $actorUserId, null, [
                'before' => $before,
                'after' => $after,
            ]);
        });
    }

    /**
     * @param array<string, mixed>|null $auditExtra merged into audit metadata
     */
    public function softDeleteBranch(int $actorUserId, int $branchId, ?array $auditExtra = null): void
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Invalid actor.');
        }
        if ($branchId <= 0) {
            throw new InvalidArgumentException('Invalid branch id.');
        }

        $this->db->transaction(function () use ($actorUserId, $branchId, $auditExtra): void {
            $row = $this->db->fetchOne(
                'SELECT id, name, code, deleted_at, organization_id FROM branches WHERE id = ? FOR UPDATE',
                [$branchId]
            );
            if ($row === null) {
                throw new InvalidArgumentException('Branch not found.');
            }
            if (($row['deleted_at'] ?? null) !== null && (string) ($row['deleted_at'] ?? '') !== '') {
                return;
            }

            $orgId = (int) $row['organization_id'];
            $this->db->query(
                'UPDATE branches SET deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND organization_id = ? AND deleted_at IS NULL',
                [$branchId, $orgId]
            );
            $this->audit->log('founder_branch_deactivated', 'branch', $branchId, $actorUserId, null, array_merge([
                'before' => $row,
            ], $auditExtra ?? []));
        });
    }

    private function assertOrganizationAcceptsBranches(int $organizationId): void
    {
        if ($organizationId <= 0) {
            throw new InvalidArgumentException('organization_id must be a positive integer.');
        }
        $row = $this->db->fetchOne(
            'SELECT id, deleted_at, suspended_at FROM organizations WHERE id = ? LIMIT 1',
            [$organizationId]
        );
        if ($row === null) {
            throw new InvalidArgumentException('Organization not found.');
        }
        if (($row['deleted_at'] ?? null) !== null && (string) ($row['deleted_at'] ?? '') !== '') {
            throw new InvalidArgumentException('Cannot create a branch for a deleted organization.');
        }
        $sat = $row['suspended_at'] ?? null;
        if ($sat !== null && (string) $sat !== '') {
            throw new InvalidArgumentException('Cannot create a branch for a suspended organization.');
        }
    }

    private function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = trim($code);

        return $code === '' ? null : substr($code, 0, 50);
    }

    private function isCodeTakenGlobally(string $code, ?int $excludeBranchId): bool
    {
        if ($excludeBranchId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM branches WHERE code = ? AND id <> ? LIMIT 1',
                [$code, $excludeBranchId]
            );
        } else {
            $row = $this->db->fetchOne('SELECT id FROM branches WHERE code = ? LIMIT 1', [$code]);
        }

        return $row !== null;
    }
}
