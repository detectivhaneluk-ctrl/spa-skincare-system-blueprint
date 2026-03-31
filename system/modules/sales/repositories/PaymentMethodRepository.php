<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use Core\App\Database;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Payment methods (`payment_methods`): **global template** (`branch_id` NULL) plus **branch overlay** rows.
 *
 * | Class | Methods |
 * | --- | --- |
 * | **1–2. Strict branch ∪ org-global-null (tenant)** | {@see listActive}, {@see listAll}, {@see isActiveCode}, {@see existsActiveNameForBranch}, {@see codeExistsForBranch} (positive branch) — same scope helpers as {@see VatRateRepository} |
 * | **2. Explicit control-plane global catalog** | {@see findGlobalCatalogMethodInResolvedTenantById}, {@see updateGlobalCatalogMethodInResolvedTenantById}, {@see archiveGlobalCatalogMethodInResolvedTenantById} |
 * | **4. Control-plane unscoped** | *(none here)* |
 *
 * **File location:** `system/modules/sales/repositories/` (settings HTTP and sales payment UI consume via {@see \Modules\Sales\Services\PaymentMethodService}).
 */
final class PaymentMethodRepository
{
    private const SQL_SELECT_ROW = 'SELECT pm.id, pm.branch_id, pm.code, pm.name, pm.type_label, pm.is_active, pm.sort_order FROM payment_methods pm';

    private const ORDER_LIST = ' ORDER BY pm.sort_order ASC, pm.name ASC';

    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * List active methods for branch (branch_id = $branchId or branch_id IS NULL), ordered by sort_order.
     * Optionally exclude a code (e.g. gift_card from manual payment form).
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, type_label:string|null, is_active:int, sort_order:int}>
     */
    public function listActive(?int $branchId = null, ?string $excludeCode = null): array
    {
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('pm', $branchId);
            $sql = self::SQL_SELECT_ROW . ' WHERE pm.is_active = 1 AND (' . $u['sql'] . ')';
            $params = $u['params'];
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('pm');
            $sql = self::SQL_SELECT_ROW . ' WHERE pm.is_active = 1' . $g['sql'];
            $params = $g['params'];
        }
        if ($excludeCode !== null && $excludeCode !== '') {
            $sql .= ' AND pm.code != ?';
            $params[] = $excludeCode;
        }
        $sql .= self::ORDER_LIST;
        $rows = $this->db->fetchAll($sql, $params);

        return array_map(fn (array $r) => [
            'id' => (int) $r['id'],
            'branch_id' => isset($r['branch_id']) && $r['branch_id'] !== '' ? (int) $r['branch_id'] : null,
            'code' => (string) $r['code'],
            'name' => (string) $r['name'],
            'type_label' => isset($r['type_label']) && trim((string) $r['type_label']) !== '' ? trim((string) $r['type_label']) : null,
            'is_active' => (int) $r['is_active'],
            'sort_order' => (int) $r['sort_order'],
        ], $rows);
    }

    /**
     * Check if a code is an active payment method for branch (global or branch-scoped).
     */
    public function isActiveCode(string $code, ?int $branchId = null): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('pm', $branchId);
            $row = $this->db->fetchOne(
                'SELECT 1 FROM payment_methods pm WHERE pm.code = ? AND pm.is_active = 1 AND (' . $u['sql'] . ') LIMIT 1',
                array_merge([$code], $u['params'])
            );
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('pm');
            $row = $this->db->fetchOne(
                'SELECT 1 FROM payment_methods pm WHERE pm.code = ? AND pm.is_active = 1' . $g['sql'] . ' LIMIT 1',
                array_merge([$code], $g['params'])
            );
        }

        return $row !== null;
    }

    /**
     * List all payment methods for branch (for admin). branch_id NULL = global template only (tenant-org anchored).
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, type_label:string|null, is_active:int, sort_order:int}>
     */
    public function listAll(?int $branchId = null): array
    {
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('pm', $branchId);
            $sql = self::SQL_SELECT_ROW . ' WHERE (' . $u['sql'] . ')' . self::ORDER_LIST;
            $params = $u['params'];
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('pm');
            $sql = self::SQL_SELECT_ROW . ' WHERE 1=1' . $g['sql'] . self::ORDER_LIST;
            $params = $g['params'];
        }
        $rows = $this->db->fetchAll($sql, $params);

        return array_map(fn (array $r) => [
            'id' => (int) $r['id'],
            'branch_id' => isset($r['branch_id']) && $r['branch_id'] !== '' ? (int) $r['branch_id'] : null,
            'code' => (string) $r['code'],
            'name' => (string) $r['name'],
            'type_label' => isset($r['type_label']) && trim((string) $r['type_label']) !== '' ? trim((string) $r['type_label']) : null,
            'is_active' => (int) $r['is_active'],
            'sort_order' => (int) $r['sort_order'],
        ], $rows);
    }

    /**
     * Get one payment method by id. **No org scope** — class 3.
     *
     * @return array{id:int, branch_id:int|null, code:string, name:string, type_label:string|null, is_active:int, sort_order:int}|null
     */
    public function findGlobalCatalogMethodInResolvedTenantById(int $id): ?array
    {
        $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('pm');
        $row = $this->db->fetchOne(
            'SELECT pm.id, pm.branch_id, pm.code, pm.name, pm.type_label, pm.is_active, pm.sort_order
             FROM payment_methods pm
             WHERE pm.id = ?' . $g['sql'],
            array_merge([$id], $g['params'])
        );
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null,
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'type_label' => isset($row['type_label']) && trim((string) $row['type_label']) !== '' ? trim((string) $row['type_label']) : null,
            'is_active' => (int) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    /**
     * Check if another active row has the same name (case-insensitive, trimmed) for this branch. Exclude id for update.
     */
    public function existsActiveNameForBranch(?int $branchId, string $name, ?int $excludeId = null): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('pm', $branchId);
            $sql = 'SELECT 1 FROM payment_methods pm WHERE pm.is_active = 1 AND (' . $u['sql'] . ') AND LOWER(TRIM(pm.name)) = LOWER(?)';
            $params = array_merge($u['params'], [$name]);
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('pm');
            $sql = 'SELECT 1 FROM payment_methods pm WHERE pm.is_active = 1' . $g['sql'] . ' AND LOWER(TRIM(pm.name)) = LOWER(?)';
            $params = array_merge($g['params'], [$name]);
        }
        if ($excludeId !== null) {
            $sql .= ' AND pm.id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * Check if code exists for branch (for uniqueness). branch_id NULL = global template scope only.
     */
    public function codeExistsForBranch(string $code, ?int $branchId, ?int $excludeId = null): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }
        if ($branchId !== null && $branchId > 0) {
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('pm');
            $sql = 'SELECT 1 FROM payment_methods pm WHERE pm.code = ? AND pm.branch_id = ?' . $frag['sql'];
            $params = array_merge([$code, $branchId], $frag['params']);
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('pm');
            $sql = 'SELECT 1 FROM payment_methods pm WHERE pm.code = ?' . $g['sql'];
            $params = array_merge([$code], $g['params']);
        }
        if ($excludeId !== null) {
            $sql .= ' AND pm.id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * Insert payment method. Returns new id.
     */
    public function create(?int $branchId, string $code, string $name, ?string $typeLabel, bool $isActive, int $sortOrder): int
    {
        $this->db->query(
            'INSERT INTO payment_methods (branch_id, code, name, type_label, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            [$branchId, $code, $name, $typeLabel, $isActive ? 1 : 0, $sortOrder]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update display fields and status. Code is not changed (payments reference it). **Id-only WHERE** — class 3.
     */
    public function updateGlobalCatalogMethodInResolvedTenantById(int $id, string $name, ?string $typeLabel, bool $isActive, int $sortOrder): void
    {
        $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('pm');
        $this->db->query(
            'UPDATE payment_methods pm
             SET pm.name = ?, pm.type_label = ?, pm.is_active = ?, pm.sort_order = ?, pm.updated_at = NOW()
             WHERE pm.id = ?' . $g['sql'],
            array_merge([$name, $typeLabel, $isActive ? 1 : 0, $sortOrder, $id], $g['params'])
        );
    }

    /**
     * Archive payment method (soft deactivation). **Id-only WHERE** — class 3.
     */
    public function archiveGlobalCatalogMethodInResolvedTenantById(int $id): void
    {
        $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('pm');
        $this->db->query(
            'UPDATE payment_methods pm SET pm.is_active = 0, pm.updated_at = NOW() WHERE pm.id = ?' . $g['sql'],
            array_merge([$id], $g['params'])
        );
    }

    // -------------------------------------------------------------------------
    // FOUNDATION-A7 PHASE-3 — canonical TenantContext-first methods
    // These are the authoritative entry points for all tenant-protected operations.
    // All methods call $ctx->requireResolvedTenant() before any data access.
    // -------------------------------------------------------------------------

    /**
     * Canonical: list active payment methods for branch (global + branch overlay), excluding optional code.
     * Fail-closed: requires resolved tenant context.
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, type_label:string|null, is_active:int, sort_order:int}>
     */
    public function listOwnedActiveMethodsForBranch(TenantContext $ctx, ?int $branchId, ?string $excludeCode = null): array
    {
        $ctx->requireResolvedTenant();
        return $this->listActive($branchId, $excludeCode);
    }

    /**
     * Canonical: list all payment methods for branch (admin view, including inactive).
     * Fail-closed: requires resolved tenant context.
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, type_label:string|null, is_active:int, sort_order:int}>
     */
    public function listOwnedAllMethodsForBranch(TenantContext $ctx, ?int $branchId = null): array
    {
        $ctx->requireResolvedTenant();
        return $this->listAll($branchId);
    }

    /**
     * Canonical: check if code is active for branch (global or branch-scoped).
     * Fail-closed: requires resolved tenant context.
     */
    public function isOwnedActiveCode(TenantContext $ctx, string $code, ?int $branchId = null): bool
    {
        $ctx->requireResolvedTenant();
        return $this->isActiveCode($code, $branchId);
    }

    /**
     * Canonical: find one payment method by id for the resolved global catalog.
     * Fail-closed: requires resolved tenant context.
     *
     * @return array{id:int, branch_id:int|null, code:string, name:string, type_label:string|null, is_active:int, sort_order:int}|null
     */
    public function findOwnedGlobalCatalogMethodById(TenantContext $ctx, int $id): ?array
    {
        $ctx->requireResolvedTenant();
        return $this->findGlobalCatalogMethodInResolvedTenantById($id);
    }

    /**
     * Canonical: check if another active row has the same name for this branch.
     * Fail-closed: requires resolved tenant context.
     */
    public function existsOwnedActiveNameForBranch(TenantContext $ctx, ?int $branchId, string $name, ?int $excludeId = null): bool
    {
        $ctx->requireResolvedTenant();
        return $this->existsActiveNameForBranch($branchId, $name, $excludeId);
    }

    /**
     * Canonical: check if code exists for branch (uniqueness check).
     * Fail-closed: requires resolved tenant context.
     */
    public function existsOwnedCodeForBranch(TenantContext $ctx, string $code, ?int $branchId, ?int $excludeId = null): bool
    {
        $ctx->requireResolvedTenant();
        return $this->codeExistsForBranch($code, $branchId, $excludeId);
    }

    /**
     * Canonical: create payment method.
     * Fail-closed: requires resolved tenant context.
     *
     * @return int new id
     */
    public function mutateCreateOwnedMethod(TenantContext $ctx, ?int $branchId, string $code, string $name, ?string $typeLabel, bool $isActive, int $sortOrder): int
    {
        $ctx->requireResolvedTenant();
        return $this->create($branchId, $code, $name, $typeLabel, $isActive, $sortOrder);
    }

    /**
     * Canonical: update payment method by id.
     * Fail-closed: requires resolved tenant context.
     */
    public function mutateUpdateOwnedGlobalCatalogMethodById(TenantContext $ctx, int $id, string $name, ?string $typeLabel, bool $isActive, int $sortOrder): void
    {
        $ctx->requireResolvedTenant();
        $this->updateGlobalCatalogMethodInResolvedTenantById($id, $name, $typeLabel, $isActive, $sortOrder);
    }

    /**
     * Canonical: archive (soft-deactivate) payment method by id.
     * Fail-closed: requires resolved tenant context.
     */
    public function mutateArchiveOwnedGlobalCatalogMethodById(TenantContext $ctx, int $id): void
    {
        $ctx->requireResolvedTenant();
        $this->archiveGlobalCatalogMethodInResolvedTenantById($id);
    }
}
