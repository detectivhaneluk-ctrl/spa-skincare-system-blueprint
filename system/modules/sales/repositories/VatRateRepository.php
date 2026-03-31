<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use Core\App\Database;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationRepositoryScope;

/**
 * VAT rates (`vat_rates`): **global template** (`branch_id` NULL) plus **branch overlay** rows.
 *
 * | Class | Methods |
 * | --- | --- |
 * | **1–2. Strict branch ∪ org-global-null (tenant)** | {@see listActive}, {@see listAll}, {@see findByCode}, {@see isActiveIdInServiceBranchCatalog}, {@see existsActiveNameForBranch}, {@see codeExistsForBranch} (positive branch) — {@see OrganizationRepositoryScope::settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause()} / {@see OrganizationRepositoryScope::settingsBackedCatalogGlobalNullBranchOrgAnchoredSql()} / {@see OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause()}; {@see bulkUpdateGlobalActiveApplicability} adds {@see OrganizationRepositoryScope::resolvedTenantOrganizationHasLiveBranchExistsClause()} on global rows only |
 * | **2. Explicit control-plane global catalog** | {@see findGlobalCatalogRateInResolvedTenantById}, {@see updateGlobalCatalogRateInResolvedTenantById}, {@see archiveGlobalCatalogRateInResolvedTenantById} |
 * | **4. Control-plane unscoped** | *(none here)* |
 *
 * **File location:** `system/modules/sales/repositories/` (settings HTTP consumes via {@see \Modules\Sales\Services\VatRateService}); there is no separate `modules/settings/repositories` copy.
 */
final class VatRateRepository
{
    private const SQL_SELECT_ROW = 'SELECT vr.id, vr.branch_id, vr.code, vr.name, vr.rate_percent, vr.is_flexible, vr.price_includes_tax, vr.applies_to_json, vr.is_active, vr.sort_order FROM vat_rates vr';

    private const ORDER_LIST = ' ORDER BY vr.sort_order ASC, vr.rate_percent ASC, vr.name ASC';

    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Row by id regardless of `branch_id` (catalog reference by primary key). **No org scope** — see class contract class 3.
     */
    public function findGlobalCatalogRateInResolvedTenantById(int $id): ?array
    {
        $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('vr');
        $row = $this->db->fetchOne(
            'SELECT vr.* FROM vat_rates vr WHERE vr.id = ?' . $g['sql'],
            array_merge([$id], $g['params'])
        );
        if (!$row) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    /**
     * Tenant runtime read: branch-owned row in the resolved org or org-global-null overlay row.
     */
    public function findTenantVisibleRateById(int $id): ?array
    {
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('vr');
        $row = $this->db->fetchOne(
            'SELECT vr.* FROM vat_rates vr WHERE vr.id = ? AND (' . $tenant['sql'] . ')',
            array_merge([$id], $tenant['params'])
        );
        if (!$row) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    /**
     * True when the id exists, is active, and appears in {@see listActive} for the same serviceBranchId
     * (`null` ⇒ global-only rows; non-null ⇒ global ∪ that branch).
     * Read-only drift audit: `system/scripts/verify_services_vat_rate_drift_readonly.php` (SERVICE-VAT-RATE-DRIFT-AUDIT-01).
     */
    public function isActiveIdInServiceBranchCatalog(int $id, ?int $serviceBranchId): bool
    {
        if ($id <= 0) {
            return false;
        }
        if ($serviceBranchId === null || $serviceBranchId <= 0) {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('vr');
            $row = $this->db->fetchOne(
                'SELECT 1 FROM vat_rates vr WHERE vr.id = ? AND vr.is_active = 1' . $g['sql'] . ' LIMIT 1',
                array_merge([$id], $g['params'])
            );
        } else {
            $u = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('vr', $serviceBranchId);
            $row = $this->db->fetchOne(
                'SELECT 1 FROM vat_rates vr WHERE vr.id = ? AND vr.is_active = 1 AND (' . $u['sql'] . ') LIMIT 1',
                array_merge([$id], $u['params'])
            );
        }

        return $row !== null;
    }

    /**
     * List active VAT rates for branch (global + branch-scoped), ordered by sort_order.
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, rate_percent:float, is_flexible:int, price_includes_tax:int, applies_to_json:list<string>, is_active:int, sort_order:int}>
     */
    public function listActive(?int $branchId = null): array
    {
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('vr', $branchId);
            $sql = self::SQL_SELECT_ROW . ' WHERE vr.is_active = 1 AND (' . $u['sql'] . ')' . self::ORDER_LIST;
            $params = $u['params'];
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('vr');
            $sql = self::SQL_SELECT_ROW . ' WHERE vr.is_active = 1' . $g['sql'] . self::ORDER_LIST;
            $params = $g['params'];
        }
        $rows = $this->db->fetchAll($sql, $params);

        return array_map(fn (array $r) => $this->normalizeRow($r), $rows);
    }

    /**
     * Find active rate by code for branch (global or branch-scoped).
     */
    public function findByCode(string $code, ?int $branchId = null): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('vr', $branchId);
            $row = $this->db->fetchOne(
                'SELECT * FROM vat_rates vr WHERE vr.code = ? AND vr.is_active = 1 AND (' . $u['sql'] . ') ORDER BY vr.branch_id DESC LIMIT 1',
                array_merge([$code], $u['params'])
            );
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('vr');
            $row = $this->db->fetchOne(
                'SELECT * FROM vat_rates vr WHERE vr.code = ? AND vr.is_active = 1' . $g['sql'] . ' LIMIT 1',
                array_merge([$code], $g['params'])
            );
        }

        return $row ? $this->normalizeRow($row) : null;
    }

    /**
     * List all VAT rates for branch (for admin). branch_id NULL = global template only (tenant-org anchored).
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, rate_percent:float, is_flexible:int, price_includes_tax:int, applies_to_json:list<string>, is_active:int, sort_order:int}>
     */
    public function listAll(?int $branchId = null): array
    {
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('vr', $branchId);
            $sql = self::SQL_SELECT_ROW . ' WHERE (' . $u['sql'] . ')' . self::ORDER_LIST;
            $params = $u['params'];
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('vr');
            $sql = self::SQL_SELECT_ROW . ' WHERE 1=1' . $g['sql'] . self::ORDER_LIST;
            $params = $g['params'];
        }
        $rows = $this->db->fetchAll($sql, $params);

        return array_map(fn (array $r) => $this->normalizeRow($r), $rows);
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
            $u = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('vr', $branchId);
            $sql = 'SELECT 1 FROM vat_rates vr WHERE vr.is_active = 1 AND (' . $u['sql'] . ') AND LOWER(TRIM(vr.name)) = LOWER(?)';
            $params = array_merge($u['params'], [$name]);
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('vr');
            $sql = 'SELECT 1 FROM vat_rates vr WHERE vr.is_active = 1' . $g['sql'] . ' AND LOWER(TRIM(vr.name)) = LOWER(?)';
            $params = array_merge($g['params'], [$name]);
        }
        if ($excludeId !== null) {
            $sql .= ' AND vr.id != ?';
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
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('vr');
            $sql = 'SELECT 1 FROM vat_rates vr WHERE vr.code = ? AND vr.branch_id = ?' . $frag['sql'];
            $params = array_merge([$code, $branchId], $frag['params']);
        } else {
            $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('vr');
            $sql = 'SELECT 1 FROM vat_rates vr WHERE vr.code = ?' . $g['sql'];
            $params = array_merge([$code], $g['params']);
        }
        if ($excludeId !== null) {
            $sql .= ' AND vr.id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * Insert VAT rate. Returns new id.
     */
    public function create(?int $branchId, string $code, string $name, float $ratePercent, bool $isFlexible, bool $priceIncludesTax, ?string $appliesToJson, bool $isActive, int $sortOrder): int
    {
        $this->db->query(
            'INSERT INTO vat_rates (branch_id, code, name, rate_percent, is_flexible, price_includes_tax, applies_to_json, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$branchId, $code, $name, $ratePercent, $isFlexible ? 1 : 0, $priceIncludesTax ? 1 : 0, $appliesToJson, $isActive ? 1 : 0, $sortOrder]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update editable fields. Code is not changed. **Id-only WHERE** — class 3; service must validate ownership.
     */
    public function updateGlobalCatalogRateInResolvedTenantById(int $id, string $name, float $ratePercent, bool $isFlexible, bool $priceIncludesTax, ?string $appliesToJson, bool $isActive, int $sortOrder): void
    {
        $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('vr');
        $this->db->query(
            'UPDATE vat_rates vr
             SET vr.name = ?, vr.rate_percent = ?, vr.is_flexible = ?, vr.price_includes_tax = ?, vr.applies_to_json = ?, vr.is_active = ?, vr.sort_order = ?, vr.updated_at = NOW()
             WHERE vr.id = ?' . $g['sql'],
            array_merge([$name, $ratePercent, $isFlexible ? 1 : 0, $priceIncludesTax ? 1 : 0, $appliesToJson, $isActive ? 1 : 0, $sortOrder, $id], $g['params'])
        );
    }

    /**
     * Archive VAT rate (soft deactivation). **Id-only WHERE** — class 3.
     */
    public function archiveGlobalCatalogRateInResolvedTenantById(int $id): void
    {
        $g = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('vr');
        $this->db->query(
            'UPDATE vat_rates vr SET vr.is_active = 0, vr.updated_at = NOW() WHERE vr.id = ?' . $g['sql'],
            array_merge([$id], $g['params'])
        );
    }

    /**
     * Settings-owned write path for VAT allocation matrix. Updates applies_to_json for active global rows.
     *
     * @param array<int, list<string>> $appliesToByVatRateId
     */
    public function bulkUpdateGlobalActiveApplicability(array $appliesToByVatRateId): void
    {
        $orgHas = $this->orgScope->resolvedTenantOrganizationHasLiveBranchExistsClause();
        foreach ($appliesToByVatRateId as $vatRateId => $tokens) {
            $appliesToJson = $tokens === [] ? null : (string) json_encode($tokens);
            $this->db->query(
                'UPDATE vat_rates SET applies_to_json = ?, updated_at = NOW() WHERE id = ? AND branch_id IS NULL AND is_active = 1' . $orgHas['sql'],
                array_merge([$appliesToJson, (int) $vatRateId], $orgHas['params'])
            );
        }
    }

    // -------------------------------------------------------------------------
    // FOUNDATION-A7 PHASE-3 — canonical TenantContext-first methods
    // These are the authoritative entry points for all tenant-protected operations.
    // All methods call $ctx->requireResolvedTenant() before any data access.
    // -------------------------------------------------------------------------

    /**
     * Canonical: list active VAT rates for branch (global + branch overlay), ordered by sort_order.
     * Fail-closed: requires resolved tenant context.
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, rate_percent:float, is_flexible:int, price_includes_tax:int, applies_to_json:list<string>, is_active:int, sort_order:int}>
     */
    public function listOwnedActiveRatesForBranch(TenantContext $ctx, ?int $branchId = null): array
    {
        $ctx->requireResolvedTenant();
        return $this->listActive($branchId);
    }

    /**
     * Canonical: list all VAT rates for branch (admin view, including inactive).
     * Fail-closed: requires resolved tenant context.
     *
     * @return list<array{id:int, branch_id:int|null, code:string, name:string, rate_percent:float, is_flexible:int, price_includes_tax:int, applies_to_json:list<string>, is_active:int, sort_order:int}>
     */
    public function listOwnedAllRatesForBranch(TenantContext $ctx, ?int $branchId = null): array
    {
        $ctx->requireResolvedTenant();
        return $this->listAll($branchId);
    }

    /**
     * Canonical: find active rate by code for branch.
     * Fail-closed: requires resolved tenant context.
     */
    public function findOwnedRateByCode(TenantContext $ctx, string $code, ?int $branchId = null): ?array
    {
        $ctx->requireResolvedTenant();
        return $this->findByCode($code, $branchId);
    }

    /**
     * Canonical: row by id for the resolved global catalog (settings admin path).
     * Fail-closed: requires resolved tenant context.
     */
    public function findOwnedGlobalCatalogRateById(TenantContext $ctx, int $id): ?array
    {
        $ctx->requireResolvedTenant();
        return $this->findGlobalCatalogRateInResolvedTenantById($id);
    }

    /**
     * Canonical: tenant runtime visible rate by id (any branch in org, or global).
     * Fail-closed: requires resolved tenant context.
     */
    public function findOwnedTenantVisibleRateById(TenantContext $ctx, int $id): ?array
    {
        $ctx->requireResolvedTenant();
        return $this->findTenantVisibleRateById($id);
    }

    /**
     * Canonical: true when the rate id is active and in the service branch catalog.
     * Fail-closed: requires resolved tenant context.
     */
    public function isOwnedActiveIdInServiceBranchCatalog(TenantContext $ctx, int $id, ?int $serviceBranchId): bool
    {
        $ctx->requireResolvedTenant();
        return $this->isActiveIdInServiceBranchCatalog($id, $serviceBranchId);
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
     * Canonical: create VAT rate.
     * Fail-closed: requires resolved tenant context.
     *
     * @return int new id
     */
    public function mutateCreateOwnedRate(TenantContext $ctx, ?int $branchId, string $code, string $name, float $ratePercent, bool $isFlexible, bool $priceIncludesTax, ?string $appliesToJson, bool $isActive, int $sortOrder): int
    {
        $ctx->requireResolvedTenant();
        return $this->create($branchId, $code, $name, $ratePercent, $isFlexible, $priceIncludesTax, $appliesToJson, $isActive, $sortOrder);
    }

    /**
     * Canonical: update VAT rate by id.
     * Fail-closed: requires resolved tenant context.
     */
    public function mutateUpdateOwnedGlobalCatalogRateById(TenantContext $ctx, int $id, string $name, float $ratePercent, bool $isFlexible, bool $priceIncludesTax, ?string $appliesToJson, bool $isActive, int $sortOrder): void
    {
        $ctx->requireResolvedTenant();
        $this->updateGlobalCatalogRateInResolvedTenantById($id, $name, $ratePercent, $isFlexible, $priceIncludesTax, $appliesToJson, $isActive, $sortOrder);
    }

    /**
     * Canonical: archive (soft-deactivate) VAT rate by id.
     * Fail-closed: requires resolved tenant context.
     */
    public function mutateArchiveOwnedGlobalCatalogRateById(TenantContext $ctx, int $id): void
    {
        $ctx->requireResolvedTenant();
        $this->archiveGlobalCatalogRateInResolvedTenantById($id);
    }

    /**
     * Canonical: bulk update applicability matrix for active global rates.
     * Fail-closed: requires resolved tenant context.
     *
     * @param array<int, list<string>> $appliesToByVatRateId
     */
    public function mutateBulkUpdateOwnedGlobalActiveApplicability(TenantContext $ctx, array $appliesToByVatRateId): void
    {
        $ctx->requireResolvedTenant();
        $this->bulkUpdateGlobalActiveApplicability($appliesToByVatRateId);
    }

    private function normalizeRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'branch_id' => isset($r['branch_id']) && $r['branch_id'] !== '' && $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'code' => (string) $r['code'],
            'name' => (string) $r['name'],
            'rate_percent' => (float) $r['rate_percent'],
            'is_flexible' => (int) ($r['is_flexible'] ?? 0),
            'price_includes_tax' => (int) ($r['price_includes_tax'] ?? 0),
            'applies_to_json' => $this->decodeAppliesTo($r['applies_to_json'] ?? null),
            'is_active' => (int) ($r['is_active'] ?? 1),
            'sort_order' => (int) ($r['sort_order'] ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    private function decodeAppliesTo(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $token) {
            if (is_string($token) && trim($token) !== '') {
                $out[] = trim($token);
            }
        }

        return array_values(array_unique($out));
    }
}
