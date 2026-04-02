<?php

declare(strict_types=1);

namespace Core\Tenant;

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Errors\AccessDeniedException;
use Core\Organization\OrganizationContext;

/**
 * TENANT-OWNED-DATA-PLANE-HARDENING-01:
 * Central fail-closed scope guard for tenant-owned protected runtime writes/linked-entity checks.
 */
final class TenantOwnedDataScopeGuard
{
    public function __construct(
        private Database $db,
        private BranchContext $branchContext,
        private OrganizationContext $organizationContext
    ) {
    }

    /**
     * @return array{organization_id:int, branch_id:int}
     */
    public function requireResolvedTenantScope(): array
    {
        $orgId = $this->organizationContext->getCurrentOrganizationId();
        $branchId = $this->branchContext->getCurrentBranchId();
        $mode = $this->organizationContext->getResolutionMode();
        if ($orgId === null || $orgId <= 0 || $branchId === null || $branchId <= 0 || $mode !== OrganizationContext::MODE_BRANCH_DERIVED) {
            throw new AccessDeniedException('Tenant data scope is unresolved.');
        }

        return ['organization_id' => (int) $orgId, 'branch_id' => (int) $branchId];
    }

    public function assertClientInScope(int $clientId, ?int $expectedBranchId = null): void
    {
        $this->assertNullBranchCapableEntityInScope('clients', $clientId, $expectedBranchId, 'client');
    }

    public function assertStaffInScope(int $staffId, ?int $expectedBranchId = null): void
    {
        $this->assertNullBranchCapableEntityInScope('staff', $staffId, $expectedBranchId, 'staff');
    }

    public function assertServiceInScope(int $serviceId, ?int $expectedBranchId = null): void
    {
        $this->assertNullBranchCapableEntityInScope('services', $serviceId, $expectedBranchId, 'service');
    }

    public function assertAppointmentInScope(int $appointmentId, ?int $expectedBranchId = null): void
    {
        $this->assertEntityInScope('appointments', $appointmentId, $expectedBranchId, 'appointment');
    }

    /**
     * Invoice owner rows may have nullable branch_id; org membership is proven via invoice.branch_id, linked client, or linked appointment.
     *
     * @return array{id:int, branch_id:int}
     */
    public function requireInvoiceBranchForDocumentOwner(int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            throw new \DomainException('Invalid invoice id.');
        }
        $scope = $this->requireResolvedTenantScope();
        $orgId = $scope['organization_id'];
        $existsBranch = static function (string $tableAlias, string $col): string {
            return "({$tableAlias}.{$col} IS NOT NULL AND EXISTS (
                SELECT 1 FROM branches b
                INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                WHERE b.id = {$tableAlias}.{$col} AND b.deleted_at IS NULL AND o.id = ?
            ))";
        };
        $cond = $existsBranch('i', 'branch_id')
            . ' OR ' . $existsBranch('c', 'branch_id')
            . ' OR ' . $existsBranch('a', 'branch_id');
        $sql = "SELECT i.id,
                COALESCE(i.branch_id, c.branch_id, a.branch_id) AS resolved_branch_id
                FROM invoices i
                LEFT JOIN clients c ON c.id = i.client_id AND c.deleted_at IS NULL
                LEFT JOIN appointments a ON a.id = i.appointment_id AND a.deleted_at IS NULL
                WHERE i.id = ? AND i.deleted_at IS NULL
                AND ({$cond})
                LIMIT 1";
        $row = $this->db->fetchOne($sql, [$invoiceId, $orgId, $orgId, $orgId]);
        if ($row === null) {
            throw new AccessDeniedException('Selected invoice is outside tenant scope.');
        }
        $resolved = isset($row['resolved_branch_id']) && $row['resolved_branch_id'] !== null && $row['resolved_branch_id'] !== ''
            ? (int) $row['resolved_branch_id']
            : 0;
        if ($resolved <= 0) {
            throw new AccessDeniedException('Selected invoice is outside branch scope.');
        }
        if ($resolved !== $scope['branch_id']) {
            throw new AccessDeniedException('Selected invoice is outside branch scope.');
        }

        return ['id' => $invoiceId, 'branch_id' => $resolved];
    }

    public function assertRoomInScope(int $roomId, ?int $expectedBranchId = null): void
    {
        $this->assertEntityInScope('rooms', $roomId, $expectedBranchId, 'room');
    }

    /**
     * Scope guard for entities that may be org-global (branch_id IS NULL).
     *
     * Used for staff (null-home/org-wide members visible in calendar columns via staffSelectableAtOperationBranchTenantClause),
     * services (org-global SKUs), and clients (branchless org-level profiles).
     *
     * Two accepted conditions:
     * (a) entity.branch_id == requiredBranchId AND that branch belongs to the current org.
     * (b) entity.branch_id IS NULL (org-global entity) AND requiredBranchId belongs to the current org.
     *
     * Rooms and appointments always carry an explicit branch_id — use assertEntityInScope for those.
     */
    private function assertNullBranchCapableEntityInScope(string $table, int $id, ?int $expectedBranchId, string $label): void
    {
        if ($id <= 0) {
            throw new \DomainException('Invalid ' . $label . ' id.');
        }
        $scope = $this->requireResolvedTenantScope();
        $requiredBranchId = $expectedBranchId !== null ? $expectedBranchId : $scope['branch_id'];
        if ($requiredBranchId <= 0) {
            throw new AccessDeniedException('Selected ' . $label . ' is outside branch scope.');
        }
        $sql = "SELECT t.id, t.branch_id
                FROM {$table} t
                WHERE t.id = ? AND t.deleted_at IS NULL
                  AND (
                    (
                      t.branch_id = ?
                      AND EXISTS (
                        SELECT 1 FROM branches b
                        INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                        WHERE b.id = t.branch_id AND b.deleted_at IS NULL AND o.id = ?
                      )
                    )
                    OR (
                      t.branch_id IS NULL
                      AND EXISTS (
                        SELECT 1 FROM branches b
                        INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                        WHERE b.id = ? AND b.deleted_at IS NULL AND o.id = ?
                      )
                    )
                  )
                LIMIT 1";
        $row = $this->db->fetchOne($sql, [
            $id,
            $requiredBranchId,
            $scope['organization_id'],
            $requiredBranchId,
            $scope['organization_id'],
        ]);
        if ($row === null) {
            throw new AccessDeniedException('Selected ' . $label . ' is outside tenant scope.');
        }
    }

    private function assertEntityInScope(string $table, int $id, ?int $expectedBranchId, string $label): void
    {
        if ($id <= 0) {
            throw new \DomainException('Invalid ' . $label . ' id.');
        }
        $scope = $this->requireResolvedTenantScope();
        $sql = "SELECT t.id, t.branch_id
                FROM {$table} t
                INNER JOIN branches b ON b.id = t.branch_id AND b.deleted_at IS NULL
                INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                WHERE t.id = ? AND t.deleted_at IS NULL AND o.id = ?
                LIMIT 1";
        $row = $this->db->fetchOne($sql, [$id, $scope['organization_id']]);
        if ($row === null) {
            throw new AccessDeniedException('Selected ' . $label . ' is outside tenant scope.');
        }
        $rowBranchId = isset($row['branch_id']) ? (int) $row['branch_id'] : 0;
        $requiredBranchId = $expectedBranchId !== null ? $expectedBranchId : $scope['branch_id'];
        if ($requiredBranchId <= 0 || $rowBranchId <= 0 || $rowBranchId !== $requiredBranchId) {
            throw new AccessDeniedException('Selected ' . $label . ' is outside branch scope.');
        }
    }
}
