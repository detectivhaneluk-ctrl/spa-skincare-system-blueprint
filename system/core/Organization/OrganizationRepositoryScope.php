<?php

declare(strict_types=1);

namespace Core\Organization;

use Core\App\Database;
use Core\Errors\AccessDeniedException;

/**
 * SQL fragments for org/branch scoping from {@see OrganizationContext}. Does not read request parameters.
 *
 * **Tenant data-plane (default):** {@see self::branchColumnOwnedByResolvedOrganizationExistsClause()} and the typed
 * delegates require a **positive** organization id and {@see OrganizationContext::MODE_BRANCH_DERIVED}. Otherwise they
 * throw {@see AccessDeniedException} — no empty SQL, no silent global fallback (WAVE-02).
 *
 * **Inventory catalog unions (explicit semantics):** {@see self::productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause()},
 * {@see self::taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause()},
 * {@see self::taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause()} — single SQL sources for branch vs
 * org-global-null catalog visibility; do not re-hand-roll these OR trees in repositories.
 *
 * **Settings-backed sales tables (`vat_rates`, `payment_methods`):** {@see self::settingsBackedCatalogGlobalNullBranchOrgAnchoredSql()}
 * and {@see self::settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause()} — same overlay shape as
 * product branch ∪ global-null, named for VAT/payment settings (delegates to the product union for the branch case).
 *
 * **Internal `notifications`:** {@see self::notificationBranchOverlayOrGlobalNullFromOperationBranchClause()},
 * {@see self::notificationGlobalNullBranchOrgAnchoredSql()}, {@see self::notificationTenantWideBranchOrGlobalNullClause()} —
 * thin delegates so notification repos do not import “settings” or “taxonomy” vocabulary for identical SQL shapes.
 *
 * **Anonymous public {@code clients} branch pin:** {@see self::publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause()} —
 * live branch + live org proof **without** resolved tenant context (booking/commerce email lock path).
 *
 * **`client_memberships`:** {@see self::clientMembershipVisibleFromBranchContextClause()} — branch-pinned row **or** {@code branch_id IS NULL}
 * with **client.home-branch** org match to the context branch’s org (**not** the catalog global-null SKU union).
 *
 * **`gift_cards`:** {@see self::giftCardVisibleFromBranchContextClause()} (same shape + explicit {@code client_id IS NOT NULL} on the null-branch arm);
 * {@see self::giftCardGlobalNullClientAnchoredInResolvedOrgClause()} for **global-only** index slice.
 *
 * **`staff_groups`:** {@see self::staffGroupVisibleFromBranchContextClause()} — branch-pinned row **or** {@code branch_id IS NULL} template row
 * under the context branch’s org (table has **no** {@code organization_id}; null-arm is **not** row-level org-partitioned — see method doc).
 *
 * **Branch-scoped marketing {@code clients}:** {@see self::clientMarketingBranchScopedOrBranchlessTenantMemberClause()} — same-branch **or** branchless row with
 * {@see self::clientProfileOrgMembershipExistsClause()} (not a catalog SKU union).
 *
 * **Staff scheduling at a concrete branch:** {@see self::staffSelectableAtOperationBranchTenantClause()} — replaces raw {@code (staff.branch_id = ? OR staff.branch_id IS NULL)}
 * with org-pinned home branch and NULL-home rules aligned to {@see \Modules\Staff\Repositories\StaffGroupRepository}.
 *
 * **Global / control-plane (explicit):** {@see self::globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause()}
 * scopes by resolved organization **without** requiring branch-derived mode; unresolved org now fails closed instead of
 * returning an empty fragment. The legacy {@see self::globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped()}
 * name remains as a compatibility wrapper only and no longer widens silently.
 *
 * **{@see self::resolvedOrganizationId()}:** positive id or `null` when unset or not positive — for guards and global helper only.
 *
 * @see system/docs/PROTECTED-DATA-PLANE-SCOPE-CONTRACT-OPS.md
 */
final class OrganizationRepositoryScope
{
    /** @see AccessDeniedException */
    public const EXCEPTION_DATA_PLANE_ORGANIZATION_REQUIRED = 'Tenant organization context is required for this data-plane operation.';

    /** @see AccessDeniedException */
    public const EXCEPTION_DATA_PLANE_BRANCH_DERIVED_REQUIRED = 'Branch-derived organization context is required for tenant data-plane scoping.';

    /** @var array<string, bool> */
    private array $schemaTableExistsCache = [];

    public function __construct(
        private OrganizationContext $organizationContext,
        private Database $db,
    ) {
    }

    /**
     * @return int|null Positive organization id when resolved; `null` when {@see OrganizationContext::getCurrentOrganizationId()} is null or ≤0
     */
    public function resolvedOrganizationId(): ?int
    {
        $id = $this->organizationContext->getCurrentOrganizationId();

        return ($id !== null && $id > 0) ? $id : null;
    }

    public function isBranchDerivedResolvedOrganizationContext(): bool
    {
        return $this->resolvedOrganizationId() !== null
            && $this->organizationContext->getResolutionMode() === OrganizationContext::MODE_BRANCH_DERIVED;
    }

    /**
     * Tenant data-plane: positive organization id only when {@see OrganizationContext::MODE_BRANCH_DERIVED} is active.
     *
     * Use for mutating org-keyed rows that have **no** {@code branch_id} column but must share the **same** resolution basis as
     * {@see branchColumnOwnedByResolvedOrganizationExistsClause()} (e.g. {@code invoice_number_sequences}). **Not** for GlobalOps /
     * repair-wide scans — those must use explicitly named APIs with their own contracts.
     *
     * @throws AccessDeniedException when organization id is unset, not positive, or resolution mode is not branch-derived
     */
    public function requireBranchDerivedOrganizationIdForDataPlane(): int
    {
        return $this->requireTenantProtectedBranchDerivedOrganizationId();
    }

    /**
     * Tenant-protected: `branch_id` must be non-null and reference an active branch in the resolved active organization.
     *
     * @return array{sql: string, params: list<mixed>}
     *
     * @throws AccessDeniedException when organization context is missing, not positive, or not {@see OrganizationContext::MODE_BRANCH_DERIVED}
     */
    public function branchColumnOwnedByResolvedOrganizationExistsClause(string $tableAlias, string $branchColumn = 'branch_id'): array
    {
        $orgId = $this->requireTenantProtectedBranchDerivedOrganizationId();

        return $this->buildBranchColumnOwnedByOrganizationExistsClause($orgId, $tableAlias, $branchColumn);
    }

    /**
     * Tenant data-plane, {@code clients}-only: org membership without requiring {@code clients.branch_id IS NOT NULL}.
     *
     * - When {@code branch_id} is set: same branch-in-organization proof as {@see self::branchColumnOwnedByResolvedOrganizationExistsClause()}.
     * - When {@code branch_id} is NULL: allow only if a non-deleted appointment, invoice, {@code appointment_series}, or
     *   marketing campaign recipient row (via a branch-scoped {@code marketing_campaigns} row) ties the client to a live branch
     *   in the resolved organization (prevents cross-tenant null-branch rows with no org anchor).
     *
     * Use from {@see \Modules\Clients\Repositories\ClientRepository} for profile/show-style reads and matching write WHERE clauses;
     * do not substitute for generic nullable-FK catalog scoping elsewhere.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function clientProfileOrgMembershipExistsClause(string $tableAlias = 'c'): array
    {
        $orgId = $this->requireTenantProtectedBranchDerivedOrganizationId();
        $a = $tableAlias;

        $optionalBranchlessOrs = '';
        $optionalParams = [];
        if ($this->databaseTableExists('appointment_series')) {
            $optionalBranchlessOrs .= "
                    OR EXISTS (
                        SELECT 1 FROM appointment_series aser
                        INNER JOIN branches b ON b.id = aser.branch_id AND b.deleted_at IS NULL
                        INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                        WHERE aser.client_id = {$a}.id AND o.id = ?
                    )";
            $optionalParams[] = $orgId;
        }
        if ($this->databaseTableExists('marketing_campaign_recipients') && $this->databaseTableExists('marketing_campaigns')) {
            $optionalBranchlessOrs .= "
                    OR EXISTS (
                        SELECT 1 FROM marketing_campaign_recipients mcr
                        INNER JOIN marketing_campaigns mc ON mc.id = mcr.campaign_id
                        INNER JOIN branches b ON b.id = mc.branch_id AND b.deleted_at IS NULL
                        INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                        WHERE mcr.client_id = {$a}.id AND mc.branch_id IS NOT NULL AND o.id = ?
                    )";
            $optionalParams[] = $orgId;
        }

        $sql = " AND (
            (
                {$a}.branch_id IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM branches b
                    INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                    WHERE b.id = {$a}.branch_id AND b.deleted_at IS NULL AND o.id = ?
                )
            )
            OR
            (
                {$a}.branch_id IS NULL
                AND (
                    EXISTS (
                        SELECT 1 FROM appointments ap
                        INNER JOIN branches b ON b.id = ap.branch_id AND b.deleted_at IS NULL
                        INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                        WHERE ap.client_id = {$a}.id AND ap.deleted_at IS NULL AND ap.branch_id IS NOT NULL AND o.id = ?
                    )
                    OR EXISTS (
                        SELECT 1 FROM invoices inv
                        INNER JOIN branches b ON b.id = inv.branch_id AND b.deleted_at IS NULL
                        INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                        WHERE inv.client_id = {$a}.id AND inv.deleted_at IS NULL AND inv.branch_id IS NOT NULL AND o.id = ?
                    ){$optionalBranchlessOrs}
                )
            )
        )";

        return ['sql' => $sql, 'params' => array_merge([$orgId, $orgId, $orgId], $optionalParams)];
    }

    /**
     * Tenant data-plane: {@code client_registration_requests} rows provably owned by the resolved organization.
     *
     * - {@code branch_id} set: branch must be a live row in the org.
     * - {@code branch_id} NULL: allowed only when {@code linked_client_id} resolves to a client that passes
     *   {@see self::clientProfileOrgMembershipExistsClause()} (fail-closed for orphan NULL/NULL rows).
     *
     * @return array{sql: string, params: list<mixed>} leading {@code AND} + EXISTS-friendly params
     */
    public function clientRegistrationRequestTenantExistsClause(string $alias = 'r'): array
    {
        $orgId = $this->requireTenantProtectedBranchDerivedOrganizationId();
        $a = $alias;
        $clientFrag = $this->clientProfileOrgMembershipExistsClause('c');
        $clientSql = ltrim($clientFrag['sql']);
        if (str_starts_with($clientSql, 'AND ')) {
            $clientSql = ltrim(substr($clientSql, 4));
        }
        $sql = " AND (
            (
                {$a}.branch_id IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM branches b
                    INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                    WHERE b.id = {$a}.branch_id AND b.deleted_at IS NULL AND o.id = ?
                )
            )
            OR
            (
                {$a}.branch_id IS NULL
                AND {$a}.linked_client_id IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM clients c
                    WHERE c.id = {$a}.linked_client_id
                      AND c.deleted_at IS NULL
                      AND ({$clientSql})
                )
            )
        )";

        return ['sql' => $sql, 'params' => array_merge([$orgId], $clientFrag['params'])];
    }

    /**
     * Tenant data-plane: EXISTS body proving {@code client_issue_flags} row is tied to a tenant-visible client
     * ({@see self::clientProfileOrgMembershipExistsClause()} on {@code clients}).
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function clientIssueFlagTenantJoinSql(string $flagAlias = 'f', string $clientAlias = 'c'): array
    {
        $this->requireTenantProtectedBranchDerivedOrganizationId();
        $frag = $this->clientProfileOrgMembershipExistsClause($clientAlias);
        $inner = ltrim($frag['sql']);
        if (str_starts_with($inner, 'AND ')) {
            $inner = ltrim(substr($inner, 4));
        }
        $f = $flagAlias;
        $c = $clientAlias;
        $sql = "INNER JOIN clients {$c} ON {$c}.id = {$f}.client_id AND {$c}.deleted_at IS NULL AND ({$inner})";

        return ['sql' => $sql, 'params' => $frag['params']];
    }

    /**
     * Tenant data-plane: {@code client_field_definitions} rows whose {@code branch_id} is a live branch in the org.
     * Rows with {@code branch_id} NULL are excluded (no org anchor in schema — SaaS fail-closed).
     *
     * @return array{sql: string, params: list<mixed>} leading {@code AND}
     */
    public function clientFieldDefinitionTenantBranchClause(string $alias = 'd'): array
    {
        $orgId = $this->requireTenantProtectedBranchDerivedOrganizationId();
        $d = $alias;
        $sql = " AND {$d}.branch_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM branches b
            INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
            WHERE b.id = {$d}.branch_id AND b.deleted_at IS NULL AND o.id = ?
        )";

        return ['sql' => $sql, 'params' => [$orgId]];
    }

    /**
     * Tenant data-plane: EXISTS proving {@code $branchId} is a live branch row in the resolved organization.
     * Used when mutating org-wide catalog rows (e.g. {@code products.branch_id IS NULL}) from a concrete branch
     * context — same org gate as {@see branchColumnOwnedByResolvedOrganizationExistsClause} without requiring a
     * non-null FK on the catalog row.
     *
     * @return array{sql: string, params: list<mixed>}
     *
     * @throws \DomainException
     */
    public function branchIdBelongsToResolvedOrganizationExistsClause(int $branchId): array
    {
        if ($branchId <= 0) {
            throw new \DomainException('Branch id must be positive.');
        }
        $orgId = $this->requireTenantProtectedBranchDerivedOrganizationId();
        $sql = ' AND EXISTS (
            SELECT 1 FROM branches b
            INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
            WHERE b.id = ? AND b.deleted_at IS NULL AND o.id = ?
        )';

        return ['sql' => $sql, 'params' => [$branchId, $orgId]];
    }

    /**
     * **Anonymous public client resolution** (`lockActiveByEmailBranch`, etc.): {@code $tableAlias.$branchColumn} must be
     * non-null and reference a **live** {@code branches} row whose {@code organizations} row is live (not soft-deleted).
     * Does **not** consult {@see OrganizationContext} — no branch-derived org resolution. Callers must still pin the
     * branch key (e.g. {@code branch_id <=> ?}) with a **positive** branch id (fail-closed when id ≤ 0 at repository entry).
     *
     * @return array{sql: string, params: list<mixed>} leading {@code AND}
     */
    public function publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause(
        string $tableAlias = 'c',
        string $branchColumn = 'branch_id'
    ): array {
        $a = $tableAlias;
        $c = $branchColumn;
        $sql = " AND {$a}.{$c} IS NOT NULL AND EXISTS (
            SELECT 1 FROM branches b
            INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
            WHERE b.id = {$a}.{$c} AND b.deleted_at IS NULL
        )";

        return ['sql' => $sql, 'params' => []];
    }

    /**
     * Inventory **products** (stock + unified sellable catalog): visible when the row is **branch-owned for
     * {@code $operationBranchId}** in the resolved org, **or** {@code branch_id IS NULL} (org-global SKU) with the same
     * org anchor via {@see self::branchIdBelongsToResolvedOrganizationExistsClause()}.
     *
     * **Canonical classes:** (1) strict branch-owned for the operation branch + (2) org-global but safe — **not** repair-only
     * null-branch tooling and **not** control-plane unscoped.
     *
     * **Differs from {@see self::taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause()}:**
     * branch arm requires {@code alias.branch_id = $operationBranchId}, not “any branch in org”.
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}); wrap as {@code AND (...)} in callers.
     *
     * @throws \DomainException when {@code $operationBranchId} is not positive
     */
    public function productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause(
        string $tableAlias,
        int $operationBranchId,
        string $branchColumn = 'branch_id'
    ): array {
        if ($operationBranchId <= 0) {
            throw new \DomainException('Operation branch id must be positive.');
        }
        $a = $tableAlias;
        $c = $branchColumn;
        $branchRowFrag = $this->branchColumnOwnedByResolvedOrganizationExistsClause($a, $c);
        $opBranchFrag = $this->branchIdBelongsToResolvedOrganizationExistsClause($operationBranchId);
        $sql = '('
            . "{$a}.{$c} = ?" . $branchRowFrag['sql']
            . ') OR ('
            . "{$a}.{$c} IS NULL" . $opBranchFrag['sql']
            . ')';

        return [
            'sql' => $sql,
            'params' => array_merge([$operationBranchId], $branchRowFrag['params'], $opBranchFrag['params']),
        ];
    }

    /**
     * Inventory **taxonomy** ({@code product_categories}, {@code product_brands}): visible when {@code branch_id} references
     * **any** live branch in the resolved org, **or** {@code branch_id IS NULL} (org-global taxonomy) anchored by proving
     * {@code $operationBranchId} belongs to that org.
     *
     * **Canonical classes:** (1) strict branch-owned (any branch column in org) + (2) org-global but safe.
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     *
     * @throws \DomainException when {@code $operationBranchId} is not positive
     */
    public function taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause(
        string $tableAlias,
        int $operationBranchId,
        string $branchColumn = 'branch_id'
    ): array {
        if ($operationBranchId <= 0) {
            throw new \DomainException('Operation branch id must be positive.');
        }
        $a = $tableAlias;
        $c = $branchColumn;
        $branchOwned = $this->branchColumnOwnedByResolvedOrganizationExistsClause($a, $c);
        $ctxFrag = $this->branchIdBelongsToResolvedOrganizationExistsClause($operationBranchId);
        $sql = '('
            . '1=1' . $branchOwned['sql']
            . ') OR ('
            . "{$a}.{$c} IS NULL" . $ctxFrag['sql']
            . ')';

        return [
            'sql' => $sql,
            'params' => array_merge($branchOwned['params'], $ctxFrag['params']),
        ];
    }

    /**
     * Same **taxonomy** visibility as {@see \Modules\Inventory\Repositories\ProductCategoryRepository::listAllLiveInResolvedTenantCatalogScope()}
     * when there is **no** concrete operation branch id (CLI/repair paths): branch-owned-in-org **or** org-global null
     * {@code branch_id} gated by
     * {@see self::resolvedTenantOrganizationHasLiveBranchExistsClause()} (org must have a live branch row).
     *
     * **Canonical classes:** (1) + (2) as above; **not** a substitute for {@see self::taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause()}
     * on HTTP request paths that know the session branch.
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     */
    public function taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause(
        string $tableAlias,
        string $branchColumn = 'branch_id'
    ): array {
        $a = $tableAlias;
        $c = $branchColumn;
        $branchOwned = $this->branchColumnOwnedByResolvedOrganizationExistsClause($a, $c);
        $orgHas = $this->resolvedTenantOrganizationHasLiveBranchExistsClause();
        $sql = '('
            . '1=1' . $branchOwned['sql']
            . ') OR ('
            . "{$a}.{$c} IS NULL" . $orgHas['sql']
            . ')';

        return [
            'sql' => $sql,
            'params' => array_merge($branchOwned['params'], $orgHas['params']),
        ];
    }

    /**
     * Settings-backed tables (`vat_rates`, `payment_methods`): **global template** rows ({@code branch_id IS NULL}) under
     * branch-derived tenant org, gated by {@see self::resolvedTenantOrganizationHasLiveBranchExistsClause()} (fail-closed
     * when org context is missing or org has no live branch).
     *
     * @return array{sql: string, params: list<mixed>} Fragment beginning with {@code  AND alias.column IS NULL ...}
     */
    public function settingsBackedCatalogGlobalNullBranchOrgAnchoredSql(
        string $tableAlias,
        string $branchColumn = 'branch_id'
    ): array {
        $a = $tableAlias;
        $c = $branchColumn;
        $orgHas = $this->resolvedTenantOrganizationHasLiveBranchExistsClause();

        return [
            'sql' => " AND {$a}.{$c} IS NULL" . $orgHas['sql'],
            'params' => $orgHas['params'],
        ];
    }

    /**
     * Settings-backed **overlay** visibility: branch row for {@code $operationBranchId} in the resolved org **or**
     * org-global-null template row anchored by that branch — **identical predicate** to
     * {@see self::productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause()}; separate name so VAT/payment code
     * does not read as “product catalog”.
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     */
    public function settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause(
        string $tableAlias,
        int $operationBranchId,
        string $branchColumn = 'branch_id'
    ): array {
        return $this->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause($tableAlias, $operationBranchId, $branchColumn);
    }

    /**
     * {@code notifications.branch_id}: branch row for {@code $operationBranchId} in resolved org **or** org-global-null
     * broadcast row — delegates to {@see self::settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause()}.
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     */
    public function notificationBranchOverlayOrGlobalNullFromOperationBranchClause(
        string $tableAlias,
        int $operationBranchId,
        string $branchColumn = 'branch_id'
    ): array {
        return $this->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause($tableAlias, $operationBranchId, $branchColumn);
    }

    /**
     * {@code notifications}: **global-null** rows only, org live-branch anchored — delegates to
     * {@see self::settingsBackedCatalogGlobalNullBranchOrgAnchoredSql()}.
     *
     * @return array{sql: string, params: list<mixed>} Leading {@code  AND alias.branch_id IS NULL ...}
     */
    public function notificationGlobalNullBranchOrgAnchoredSql(
        string $tableAlias,
        string $branchColumn = 'branch_id'
    ): array {
        return $this->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql($tableAlias, $branchColumn);
    }

    /**
     * {@code notifications}: **tenant-wide** visibility (any branch in org **or** org-global-null) when no single operation
     * branch is in scope — delegates to {@see self::taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause()}.
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     */
    public function notificationTenantWideBranchOrGlobalNullClause(
        string $tableAlias,
        string $branchColumn = 'branch_id'
    ): array {
        return $this->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause($tableAlias, $branchColumn);
    }

    /**
     * Global control-plane cron only: a {@code client_memberships} row is anchored to a **non-deleted** branch and organization.
     * Branch-pinned rows use {@code alias.branch_id}; null-branch rows use the linked client's home {@code clients.branch_id}.
     * No tenant HTTP session context. Used to make cross-tenant listing queries fail-closed (exclude orphan / unanchored rows).
     *
     * @param non-empty-string $membershipTableAlias
     * @param non-empty-string $membershipBranchColumn
     * @param non-empty-string $clientIdColumn
     *
     * @return string Parenthetical SQL boolean
     */
    public function clientMembershipRowAnchoredToLiveOrganizationSql(
        string $membershipTableAlias = 'cm',
        string $membershipBranchColumn = 'branch_id',
        string $clientIdColumn = 'client_id'
    ): string {
        $m = $membershipTableAlias;
        $bc = $membershipBranchColumn;
        $cid = $clientIdColumn;

        return '('
            . '('
            . "{$m}.{$bc} IS NOT NULL AND {$m}.{$bc} <> 0 AND EXISTS ("
            . 'SELECT 1 FROM branches b '
            . 'INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL '
            . "WHERE b.id = {$m}.{$bc} AND b.deleted_at IS NULL"
            . ')) OR ('
            . "{$m}.{$bc} IS NULL AND EXISTS ("
            . 'SELECT 1 FROM clients cl '
            . 'INNER JOIN branches b ON b.id = cl.branch_id AND b.deleted_at IS NULL '
            . 'INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL '
            . "WHERE cl.id = {$m}.{$cid} AND cl.deleted_at IS NULL"
            . '))'
            . ')';
    }

    /**
     * {@code client_memberships} visibility from HTTP/cron **branch context** {@code $branchContextId}:
     *
     * - **Branch-pinned:** {@code alias.branch_id} non-null, equals {@code $branchContextId}, and passes
     *   {@see self::branchColumnOwnedByResolvedOrganizationExistsClause()} on the membership alias.
     * - **Null membership branch (legacy / org-global row):** {@code alias.branch_id IS NULL} and the linked **client** row
     *   has a **non-null** {@code clients.branch_id} whose branch’s {@code organization_id} equals the organization of
     *   {@code $branchContextId} (subquery on {@code branches bctx}).
     *
     * **Do not substitute** {@see self::settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause()} — null
     * membership rows are **not** org-template SKUs; they are anchored through **client** branch membership.
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     *
     * @throws \DomainException when {@code $branchContextId} is not positive
     */
    public function clientMembershipVisibleFromBranchContextClause(
        string $membershipTableAlias,
        int $branchContextId,
        string $membershipBranchColumn = 'branch_id',
        string $clientIdColumn = 'client_id'
    ): array {
        if ($branchContextId <= 0) {
            throw new \DomainException('Branch context id must be positive.');
        }
        $cm = $membershipTableAlias;
        $c = $membershipBranchColumn;
        $frag = $this->branchColumnOwnedByResolvedOrganizationExistsClause($cm, $c);
        $orgSub = '(SELECT bctx.organization_id FROM branches bctx WHERE bctx.id = ? AND bctx.deleted_at IS NULL LIMIT 1)';
        $cid = $clientIdColumn;
        $sql = '('
            . '(' . "{$cm}.{$c} IS NOT NULL AND {$cm}.{$c} = ?" . $frag['sql'] . ')'
            . ' OR ('
            . "{$cm}.{$c} IS NULL AND EXISTS ("
            . 'SELECT 1 FROM clients cl '
            . 'INNER JOIN branches b ON b.id = cl.branch_id AND b.deleted_at IS NULL '
            . "WHERE cl.id = {$cm}.{$cid} AND cl.deleted_at IS NULL AND b.organization_id = {$orgSub}"
            . ')'
            . ')';

        return [
            'sql' => $sql,
            'params' => array_merge([$branchContextId], $frag['params'], [$branchContextId]),
        ];
    }

    /**
     * {@code gift_cards}: same **context-branch org anchor** as {@see self::clientMembershipVisibleFromBranchContextClause()},
     * plus explicit {@code client_id IS NOT NULL} on the null-{@code branch_id} arm (schema allows orphan NULL {@code client_id}).
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     *
     * @throws \DomainException when {@code $branchContextId} is not positive
     */
    public function giftCardVisibleFromBranchContextClause(
        string $giftCardAlias,
        int $branchContextId,
        string $branchColumn = 'branch_id',
        string $clientIdColumn = 'client_id'
    ): array {
        if ($branchContextId <= 0) {
            throw new \DomainException('Branch context id must be positive.');
        }
        $g = $giftCardAlias;
        $c = $branchColumn;
        $cid = $clientIdColumn;
        $frag = $this->branchColumnOwnedByResolvedOrganizationExistsClause($g, $c);
        $orgSub = '(SELECT bctx.organization_id FROM branches bctx WHERE bctx.id = ? AND bctx.deleted_at IS NULL LIMIT 1)';
        $sql = '(' . "{$g}.{$c} IS NOT NULL AND {$g}.{$c} = ?" . $frag['sql'] . ')'
            . ' OR ('
            . "{$g}.{$c} IS NULL AND {$g}.{$cid} IS NOT NULL AND EXISTS ("
            . 'SELECT 1 FROM clients cl '
            . 'INNER JOIN branches b ON b.id = cl.branch_id AND b.deleted_at IS NULL '
            . "WHERE cl.id = {$g}.{$cid} AND cl.deleted_at IS NULL AND b.organization_id = {$orgSub}"
            . ')'
            . ')';

        return [
            'sql' => $sql,
            'params' => array_merge([$branchContextId], $frag['params'], [$branchContextId]),
        ];
    }

    /**
     * {@code gift_cards} **global-only** admin index slice: {@code branch_id IS NULL}, {@code client_id} set, client home branch in
     * the **branch-derived** resolved tenant org (fail-closed with {@see self::requireTenantProtectedBranchDerivedOrganizationId()}).
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     */
    public function giftCardGlobalNullClientAnchoredInResolvedOrgClause(
        string $giftCardAlias = 'gc',
        string $branchColumn = 'branch_id',
        string $clientIdColumn = 'client_id'
    ): array {
        $orgId = $this->requireTenantProtectedBranchDerivedOrganizationId();
        $g = $giftCardAlias;
        $c = $branchColumn;
        $cid = $clientIdColumn;
        $sql = '('
            . "{$g}.{$c} IS NULL AND {$g}.{$cid} IS NOT NULL AND EXISTS ("
            . 'SELECT 1 FROM clients cl '
            . 'INNER JOIN branches b ON b.id = cl.branch_id AND b.deleted_at IS NULL '
            . "WHERE cl.id = {$g}.{$cid} AND cl.deleted_at IS NULL AND b.organization_id = ?"
            . ')'
            . ')';

        return ['sql' => $sql, 'params' => [$orgId]];
    }

    /**
     * {@code staff_groups}: branch-pinned row in the context branch’s organization, **or** {@code branch_id IS NULL} “org template” row.
     *
     * **Schema honesty:** {@code staff_groups} has **no** {@code organization_id}. The null-{@code branch_id} arm only proves the context
     * branch’s organization has a live branch (via {@code bctx}); it does **not** tie a specific row to an organization. In a single-tenant
     * deployment or when null-template rows are installation-partitioned by convention, this matches legacy behavior; in shared multi-tenant
     * databases, null-template rows remain a **residual cross-tenant** risk unless a future migration adds an org FK.
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     *
     * @throws \DomainException when {@code $branchContextId} is not positive
     */
    public function staffGroupVisibleFromBranchContextClause(
        string $tableAlias,
        int $branchContextId,
        string $branchColumn = 'branch_id'
    ): array {
        if ($branchContextId <= 0) {
            throw new \DomainException('Branch context id must be positive.');
        }
        $t = $tableAlias;
        $c = $branchColumn;
        $frag = $this->branchColumnOwnedByResolvedOrganizationExistsClause($t, $c);
        $orgSub = '(SELECT bctx.organization_id FROM branches bctx WHERE bctx.id = ? AND bctx.deleted_at IS NULL LIMIT 1)';
        $sql = '('
            . '(' . "{$t}.{$c} IS NOT NULL AND {$t}.{$c} = ?" . $frag['sql'] . ')'
            . ' OR ('
            . "{$t}.{$c} IS NULL AND EXISTS ("
            . 'SELECT 1 FROM branches b '
            . 'WHERE b.organization_id = ' . $orgSub . ' AND b.deleted_at IS NULL'
            . ')'
            . ')';

        return [
            'sql' => $sql,
            'params' => array_merge([$branchContextId], $frag['params'], [$branchContextId]),
        ];
    }

    /**
     * Tenant data-plane: EXISTS proving the resolved organization has at least one live {@code branches} row.
     * Use with nullable {@code branch_id} catalog rows (org-global product/category/brand) under branch-derived org context.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function resolvedTenantOrganizationHasLiveBranchExistsClause(): array
    {
        $orgId = $this->requireTenantProtectedBranchDerivedOrganizationId();
        $sql = ' AND EXISTS (
            SELECT 1 FROM branches b
            INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
            WHERE o.id = ? AND b.deleted_at IS NULL
        )';

        return ['sql' => $sql, 'params' => [$orgId]];
    }

    /**
     * Smallest live branch id in the resolved tenant organization (anchor for {@code findInTenantScope} on org-global catalog rows).
     *
     * @return int|null When branch-derived org context is missing or no branch rows exist
     */
    public function getAnyLiveBranchIdForResolvedTenantOrganization(): ?int
    {
        try {
            $orgId = $this->requireTenantProtectedBranchDerivedOrganizationId();
        } catch (AccessDeniedException) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT b.id FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
             WHERE o.id = ? AND b.deleted_at IS NULL
             ORDER BY b.id ASC
             LIMIT 1',
            [$orgId]
        );

        return $row !== null && isset($row['id']) ? (int) $row['id'] : null;
    }

    /**
     * Control-plane / bootstrap only: same EXISTS predicate as tenant helpers, but does **not** require branch-derived mode.
     * Requires a resolved positive organization id and fails closed otherwise.
     *
     * @return array{sql: string, params: list<mixed>}
     *
     * @throws AccessDeniedException when organization context is missing or not positive
     */
    public function globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause(
        string $tableAlias,
        string $branchColumn = 'branch_id'
    ): array {
        $orgId = $this->resolvedOrganizationId();
        if ($orgId === null) {
            throw new AccessDeniedException(self::EXCEPTION_DATA_PLANE_ORGANIZATION_REQUIRED);
        }

        return $this->buildBranchColumnOwnedByOrganizationExistsClause($orgId, $tableAlias, $branchColumn);
    }

    /**
     * Compatibility wrapper for legacy call sites. Despite the old name, this no longer returns an empty/unscoped fragment;
     * it delegates to {@see self::globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause()} and fails closed when org is unresolved.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped(
        string $tableAlias,
        string $branchColumn = 'branch_id'
    ): array {
        return $this->globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause($tableAlias, $branchColumn);
    }

    /**
     * Delegates to {@see self::branchColumnOwnedByResolvedOrganizationExistsClause()} on `marketing_campaigns.branch_id`.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function marketingCampaignBranchOrgExistsClause(string $campaignTableAlias): array
    {
        return $this->branchColumnOwnedByResolvedOrganizationExistsClause($campaignTableAlias, 'branch_id');
    }

    /**
     * {@code clients} visibility for **branch-context marketing** (campaigns, automations, segment resolution): same-branch row
     * **or** {@code branch_id IS NULL}, with {@see self::clientProfileOrgMembershipExistsClause()} so branchless PII rows stay
     * org-anchored (identical predicate shape to {@see \Modules\Clients\Repositories\ClientRepository::findLiveReadableForProfile()}
     * when {@code $currentBranchId} is set).
     *
     * Replaces hand-rolled {@code (alias.branch_id = ? OR alias.branch_id IS NULL)} without org proof on the NULL arm.
     *
     * @return array{sql: string, params: list<mixed>} leading {@code AND ...}
     *
     * @throws \DomainException when {@code $branchContextId} is not positive
     */
    public function clientMarketingBranchScopedOrBranchlessTenantMemberClause(string $tableAlias, int $branchContextId): array
    {
        if ($branchContextId <= 0) {
            throw new \DomainException('Branch context id must be positive.');
        }
        $a = $tableAlias;
        $profile = $this->clientProfileOrgMembershipExistsClause($a);

        return [
            'sql' => " AND ({$a}.branch_id IS NULL OR {$a}.branch_id = ?)" . $profile['sql'],
            'params' => array_merge([$branchContextId], $profile['params']),
        ];
    }

    /**
     * Tenant {@code staff} rows for SQL pre-selection at {@code $operationBranchId} (scheduling / availability).
     *
     * - **Pinned home branch:** {@code alias.branch_id = $operationBranchId} and branch is a live row in the resolved org.
     * - **NULL home branch:** allow when the branch has **no** active branch-pinned groups in the org (open roster), **or** the staff is a member
     *   of at least one active {@code staff_groups} row pinned to {@code $operationBranchId} in the org — aligned with
     *   {@see \Modules\Staff\Repositories\StaffGroupRepository::hasActiveGroupsForBranch()} and
     *   {@see \Modules\Staff\Repositories\StaffGroupRepository::isStaffInAnyActiveGroupForBranch()} for positive branch ids.
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean (no leading {@code AND}).
     */
    public function staffSelectableAtOperationBranchTenantClause(string $staffTableAlias, int $operationBranchId): array
    {
        if ($operationBranchId <= 0) {
            throw new \DomainException('Operation branch id must be positive.');
        }
        $s = $staffTableAlias;
        $stOwn = $this->branchColumnOwnedByResolvedOrganizationExistsClause($s, 'branch_id');
        $pinned = '(' . "{$s}.branch_id = ?" . $stOwn['sql'] . ')';

        $sgMemberFrag = $this->branchColumnOwnedByResolvedOrganizationExistsClause('sg', 'branch_id');
        $memberExists = 'EXISTS (SELECT 1 FROM staff_group_members sgm
            INNER JOIN staff_groups sg ON sg.id = sgm.staff_group_id AND sg.deleted_at IS NULL AND sg.is_active = 1
            WHERE sgm.staff_id = ' . $s . ".id AND sg.branch_id = ?" . $sgMemberFrag['sql'] . ')';

        $sghFrag = $this->branchColumnOwnedByResolvedOrganizationExistsClause('sgh', 'branch_id');
        $noActiveBranchGroup = 'NOT EXISTS (SELECT 1 FROM staff_groups sgh WHERE sgh.deleted_at IS NULL AND sgh.is_active = 1 AND sgh.branch_id = ?' . $sghFrag['sql'] . ')';

        $nullHome = '(' . "{$s}.branch_id IS NULL AND ({$memberExists} OR {$noActiveBranchGroup})" . ')';

        $sql = '(' . $pinned . ' OR ' . $nullHome . ')';
        $params = array_merge(
            [$operationBranchId],
            $stOwn['params'],
            [$operationBranchId],
            $sgMemberFrag['params'],
            [$operationBranchId],
            $sghFrag['params']
        );

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * FOUNDATION-14 — `payroll_runs.branch_id` (NOT NULL in schema).
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function payrollRunBranchOrgExistsClause(string $runTableAlias): array
    {
        return $this->branchColumnOwnedByResolvedOrganizationExistsClause($runTableAlias, 'branch_id');
    }

    /**
     * FOUNDATION-14 — nullable `payroll_compensation_rules.branch_id`.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function payrollCompensationRuleBranchOrgExistsClause(string $ruleTableAlias): array
    {
        return $this->branchColumnOwnedByResolvedOrganizationExistsClause($ruleTableAlias, 'branch_id');
    }

    private function databaseTableExists(string $table): bool
    {
        if (array_key_exists($table, $this->schemaTableExistsCache)) {
            return $this->schemaTableExistsCache[$table];
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        );
        $this->schemaTableExistsCache[$table] = $row !== null;

        return $this->schemaTableExistsCache[$table];
    }

    /**
     * @throws \DomainException
     */
    private function requireTenantProtectedBranchDerivedOrganizationId(): int
    {
        $id = $this->resolvedOrganizationId();
        if ($id === null) {
            throw new AccessDeniedException(self::EXCEPTION_DATA_PLANE_ORGANIZATION_REQUIRED);
        }
        if ($this->organizationContext->getResolutionMode() !== OrganizationContext::MODE_BRANCH_DERIVED) {
            throw new AccessDeniedException(self::EXCEPTION_DATA_PLANE_BRANCH_DERIVED_REQUIRED);
        }

        return $id;
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function buildBranchColumnOwnedByOrganizationExistsClause(
        int $orgId,
        string $tableAlias,
        string $branchColumn
    ): array {
        $a = $tableAlias;
        $c = $branchColumn;
        $sql = " AND {$a}.{$c} IS NOT NULL AND EXISTS (
            SELECT 1 FROM branches b
            INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
            WHERE b.id = {$a}.{$c} AND b.deleted_at IS NULL AND o.id = ?
        )";

        return ['sql' => $sql, 'params' => [$orgId]];
    }
}
