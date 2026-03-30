<?php

declare(strict_types=1);

namespace Modules\Memberships\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Memberships\Services\MembershipBenefitEntitlementPolicy;

/**
 * Client membership rows: branch-pinned **or** {@code branch_id IS NULL} with **client home-branch** org anchor
 * ({@see OrganizationRepositoryScope::clientMembershipVisibleFromBranchContextClause()}).
 *
 * | Class | Methods |
 * | --- | --- |
 * | **1–2. Tenant branch context** | {@see findInTenantScope}, {@see findForUpdateInTenantScope}, {@see listInTenantScope}, {@see countInTenantScope}, {@see lockWithDefinitionInTenantScope}, {@see updateInTenantScope}, {@see lockWithDefinitionForBillingInTenantScope}, {@see findBlockingIssuanceRowInTenantScope} |
 * | **3. Legacy / repair** | {@see find}, {@see findForUpdate}, {@see lockWithDefinition}, {@see lockWithDefinitionForBilling} when org context does not resolve — id-only FOR UPDATE or any-live-branch anchor; {@see updateRepairOrUnscopedById} |
 * | **4. Control-plane cross-tenant** | {@see listActiveNonExpiredForRenewalScanGlobalOps}, {@see listExpiryTerminalCandidatesForGlobalCron} — **explicit** global cron reads; not tenant HTTP |
 */
final class ClientMembershipRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function clientMembershipTenantVisibility(int $branchContextId): array
    {
        return $this->orgScope->clientMembershipVisibleFromBranchContextClause('cm', $branchContextId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInTenantScope(int $id, int $branchContextId): ?array
    {
        $vis = $this->clientMembershipTenantVisibility($branchContextId);

        return $this->db->fetchOne(
            'SELECT cm.*,
                    md.name AS definition_name,
                    md.duration_days AS definition_duration_days,
                    c.first_name AS client_first_name,
                    c.last_name AS client_last_name
             FROM client_memberships cm
             INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id
             INNER JOIN clients c ON c.id = cm.client_id
             WHERE cm.id = ? AND (' . $vis['sql'] . ')',
            array_merge([$id], $vis['params'])
        );
    }

    /**
     * Row lock for lifecycle / HTTP mutations (transaction required).
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdateInTenantScope(int $id, int $branchContextId): ?array
    {
        $vis = $this->clientMembershipTenantVisibility($branchContextId);

        return $this->db->fetchOne(
            'SELECT cm.* FROM client_memberships cm
             WHERE cm.id = ? AND (' . $vis['sql'] . ') FOR UPDATE',
            array_merge([$id], $vis['params'])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInTenantScope(array $filters, int $branchContextId, int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $vis = $this->clientMembershipTenantVisibility($branchContextId);
        $sql = 'SELECT cm.*,
                       md.name AS definition_name,
                       md.duration_days AS definition_duration_days,
                       c.first_name AS client_first_name,
                       c.last_name AS client_last_name
                FROM client_memberships cm
                INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id
                INNER JOIN clients c ON c.id = cm.client_id
                WHERE (' . $vis['sql'] . ')';
        $params = $vis['params'];

        if (!empty($filters['status'])) {
            $sql .= ' AND cm.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (md.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)';
            $params = array_merge($params, [$q, $q, $q]);
        }
        if (array_key_exists('client_id', $filters) && $filters['client_id']) {
            $sql .= ' AND cm.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (array_key_exists('membership_definition_id', $filters) && $filters['membership_definition_id']) {
            $sql .= ' AND cm.membership_definition_id = ?';
            $params[] = (int) $filters['membership_definition_id'];
        }
        $sql .= ' ORDER BY cm.created_at DESC, cm.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function countInTenantScope(array $filters, int $branchContextId): int
    {
        $vis = $this->clientMembershipTenantVisibility($branchContextId);
        $sql = 'SELECT COUNT(*) AS c
                FROM client_memberships cm
                INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id
                INNER JOIN clients c ON c.id = cm.client_id
                WHERE (' . $vis['sql'] . ')';
        $params = $vis['params'];
        if (!empty($filters['status'])) {
            $sql .= ' AND cm.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (md.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)';
            $params = array_merge($params, [$q, $q, $q]);
        }
        if (array_key_exists('client_id', $filters) && $filters['client_id']) {
            $sql .= ' AND cm.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (array_key_exists('membership_definition_id', $filters) && $filters['membership_definition_id']) {
            $sql .= ' AND cm.membership_definition_id = ?';
            $params[] = (int) $filters['membership_definition_id'];
        }
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Row lock for lifecycle / expiry finalization (transaction required).
     * Tenant data-plane: uses {@see getAnyLiveBranchIdForResolvedTenantOrganization} + {@see findForUpdateInTenantScope};
     * repair/cron without branch-derived org: id-only lock (same family as invoice-plane OrUnscoped tooling).
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        $any = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
        if ($any !== null && $any > 0) {
            return $this->findForUpdateInTenantScope($id, $any);
        }

        return $this->db->fetchOne(
            'SELECT * FROM client_memberships WHERE id = ? FOR UPDATE',
            [$id]
        );
    }

    /**
     * Read with definition/client joins. Under resolved branch-derived org, uses {@see findInTenantScope};
     * otherwise legacy-global read for repair/cron.
     */
    public function find(int $id): ?array
    {
        $any = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
        if ($any !== null && $any > 0) {
            return $this->findInTenantScope($id, $any);
        }

        return $this->db->fetchOne(
            'SELECT cm.*,
                    md.name AS definition_name,
                    md.duration_days AS definition_duration_days,
                    c.first_name AS client_first_name,
                    c.last_name AS client_last_name
             FROM client_memberships cm
             INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id
             INNER JOIN clients c ON c.id = cm.client_id
             WHERE cm.id = ?',
            [$id]
        );
    }

    /**
     * Row lock for benefit consumption (transaction required). Prefer over {@see lockWithDefinition} when branch pin is known.
     *
     * @return array<string, mixed>|null
     */
    public function lockWithDefinitionInTenantScope(int $id, int $branchContextId): ?array
    {
        $vis = $this->clientMembershipTenantVisibility($branchContextId);

        return $this->db->fetchOne(
            'SELECT cm.*,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.name\'))
                        ELSE md.name
                    END AS definition_name,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.benefits_json\')
                        ELSE md.benefits_json
                    END AS definition_benefits_json,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.definition_status\'))
                        ELSE md.status
                    END AS definition_status,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL THEN NULL
                        ELSE md.deleted_at
                    END AS definition_deleted_at
             FROM client_memberships cm
             INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id
             WHERE cm.id = ? AND (' . $vis['sql'] . ')
             FOR UPDATE',
            array_merge([$id], $vis['params'])
        );
    }

    /**
     * Row lock for benefit consumption inside an open transaction.
     * Tenant: any live branch anchor in org when branch-derived context resolves; else repair id-only lock.
     *
     * @return array<string, mixed>|null
     */
    public function lockWithDefinition(int $id): ?array
    {
        $any = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
        if ($any !== null && $any > 0) {
            return $this->lockWithDefinitionInTenantScope($id, $any);
        }

        return $this->db->fetchOne(
            'SELECT cm.*,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.name\'))
                        ELSE md.name
                    END AS definition_name,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.benefits_json\')
                        ELSE md.benefits_json
                    END AS definition_benefits_json,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.definition_status\'))
                        ELSE md.status
                    END AS definition_status,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL THEN NULL
                        ELSE md.deleted_at
                    END AS definition_deleted_at
             FROM client_memberships cm
             INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id
             WHERE cm.id = ?
             FOR UPDATE',
            [$id]
        );
    }

    public function create(array $data): int
    {
        $this->db->insert('client_memberships', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    /**
     * Tenant data-plane: {@code UPDATE} only when {@code cm.id} matches resolved-org predicate (same family as {@see findInTenantScope}).
     */
    public function updateInTenantScope(int $id, array $data, int $branchContextId): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $vis = $this->clientMembershipTenantVisibility($branchContextId);
        $cols = array_map(static fn (string $k): string => 'cm.' . $k . ' = ?', array_keys($norm));
        $vals = array_values($norm);
        $sql = 'UPDATE client_memberships cm SET ' . implode(', ', $cols) . ' WHERE cm.id = ? AND (' . $vis['sql'] . ')';
        $params = array_merge($vals, [$id], $vis['params']);
        $this->db->query($sql, $params);
    }

    /**
     * Repair / cron / ops only: id-keyed {@code UPDATE} with **no** intrinsic org predicate. Not for tenant HTTP paths.
     */
    public function updateRepairOrUnscopedById(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE client_memberships SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    /**
     * Cross-tenant scheduled jobs only: active memberships not yet calendar-expired (operational definition join).
     * **Not** a tenant HTTP data-plane read — used by renewal reminder cron that scans all orgs in one process.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActiveNonExpiredForRenewalScanGlobalOps(): array
    {
        $defOp = MembershipBenefitEntitlementPolicy::sqlMembershipDefinitionJoinOperational('md');

        return $this->db->fetchAll(
            "SELECT cm.*,
                    md.name AS definition_name,
                    c.first_name AS client_first_name,
                    c.last_name AS client_last_name
             FROM client_memberships cm
             INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id AND {$defOp}
             INNER JOIN clients c ON c.id = cm.client_id
             WHERE cm.status = ?
               AND cm.ends_at >= ?
               AND COALESCE(cm.cancel_at_period_end, 0) = 0
             ORDER BY cm.ends_at ASC, cm.id ASC",
            ['active', date('Y-m-d')]
        );
    }

    /**
     * Control-plane cron only: candidates for {@see \Modules\Memberships\Services\MembershipLifecycleService::runExpiryPass}
     * after calendar {@code ends_at} (active/paused). Rows must be anchored to a live branch + organization; {@code lock_branch_id}
     * is the pin for {@see findForUpdateInTenantScope} / {@see findInTenantScope} (membership branch or client home branch).
     *
     * @param int|null $branchScopeId when set, restrict to {@code cm.branch_id =} this branch (per-branch cron mode)
     *
     * @return list<array{id: int, lock_branch_id: int, ends_at: mixed, cancel_at_period_end: mixed, status: mixed}>
     */
    public function listExpiryTerminalCandidatesForGlobalCron(?int $branchScopeId = null): array
    {
        $defOp = MembershipBenefitEntitlementPolicy::sqlMembershipDefinitionJoinOperational('md');
        $anchor = $this->orgScope->clientMembershipRowAnchoredToLiveOrganizationSql('cm');
        $sql = "SELECT cm.id,
                       cm.ends_at,
                       cm.cancel_at_period_end,
                       cm.status,
                       COALESCE(NULLIF(cm.branch_id, 0), c.branch_id) AS lock_branch_id
                FROM client_memberships cm
                INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id AND {$defOp}
                INNER JOIN clients c ON c.id = cm.client_id AND c.deleted_at IS NULL
                WHERE cm.status IN ('active', 'paused')
                  AND cm.ends_at < CURDATE()
                  AND ({$anchor})
                  AND COALESCE(NULLIF(cm.branch_id, 0), c.branch_id) IS NOT NULL
                  AND COALESCE(NULLIF(cm.branch_id, 0), c.branch_id) > 0";
        $params = [];
        if ($branchScopeId !== null && $branchScopeId > 0) {
            $sql .= ' AND cm.branch_id = ?';
            $params[] = $branchScopeId;
        }
        $sql .= ' ORDER BY cm.id ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Lock client membership + definition billing columns for engine processing (branch pin = membership or reconcile branch).
     *
     * @return array<string, mixed>|null
     */
    public function lockWithDefinitionForBillingInTenantScope(int $id, int $branchContextId): ?array
    {
        $vis = $this->clientMembershipTenantVisibility($branchContextId);

        return $this->db->fetchOne(
            'SELECT cm.*,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.billing_enabled\')) AS UNSIGNED)
                        ELSE md.billing_enabled
                    END AS def_billing_enabled,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.billing_interval_unit\'))
                        ELSE md.billing_interval_unit
                    END AS def_billing_interval_unit,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.billing_interval_count\')) AS UNSIGNED)
                        ELSE md.billing_interval_count
                    END AS def_billing_interval_count,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.renewal_price\') AS DECIMAL(12,2))
                        ELSE md.renewal_price
                    END AS def_renewal_price,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.price\') AS DECIMAL(12,2))
                        ELSE md.price
                    END AS def_price,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.renewal_invoice_due_days\')) AS UNSIGNED)
                        ELSE md.renewal_invoice_due_days
                    END AS def_renewal_invoice_due_days,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.billing_auto_renew_enabled\')) AS UNSIGNED)
                        ELSE md.billing_auto_renew_enabled
                    END AS def_billing_auto_renew_enabled,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.duration_days\')) AS UNSIGNED)
                        ELSE md.duration_days
                    END AS def_duration_days,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.name\'))
                        ELSE md.name
                    END AS def_name,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.definition_status\'))
                        ELSE md.status
                    END AS def_status,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL THEN NULL
                        ELSE md.deleted_at
                    END AS def_deleted_at
             FROM client_memberships cm
             INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id
             WHERE cm.id = ? AND (' . $vis['sql'] . ')
             FOR UPDATE',
            array_merge([$id], $vis['params'])
        );
    }

    /**
     * Renewal engine lock: tenant branch pin when {@code membershipBranchId} is set; else org anchor or repair id-only.
     *
     * @return array<string, mixed>|null
     */
    public function lockWithDefinitionForBilling(int $id, ?int $membershipBranchId = null): ?array
    {
        if ($membershipBranchId !== null && $membershipBranchId > 0) {
            return $this->lockWithDefinitionForBillingInTenantScope($id, $membershipBranchId);
        }
        $any = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
        if ($any !== null && $any > 0) {
            return $this->lockWithDefinitionForBillingInTenantScope($id, $any);
        }

        return $this->db->fetchOne(
            'SELECT cm.*,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.billing_enabled\')) AS UNSIGNED)
                        ELSE md.billing_enabled
                    END AS def_billing_enabled,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.billing_interval_unit\'))
                        ELSE md.billing_interval_unit
                    END AS def_billing_interval_unit,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.billing_interval_count\')) AS UNSIGNED)
                        ELSE md.billing_interval_count
                    END AS def_billing_interval_count,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.renewal_price\') AS DECIMAL(12,2))
                        ELSE md.renewal_price
                    END AS def_renewal_price,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.price\') AS DECIMAL(12,2))
                        ELSE md.price
                    END AS def_price,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.renewal_invoice_due_days\')) AS UNSIGNED)
                        ELSE md.renewal_invoice_due_days
                    END AS def_renewal_invoice_due_days,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.billing_auto_renew_enabled\')) AS UNSIGNED)
                        ELSE md.billing_auto_renew_enabled
                    END AS def_billing_auto_renew_enabled,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.duration_days\')) AS UNSIGNED)
                        ELSE md.duration_days
                    END AS def_duration_days,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.name\'))
                        ELSE md.name
                    END AS def_name,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL
                        THEN JSON_UNQUOTE(JSON_EXTRACT(cm.entitlement_snapshot_json, \'$.definition_status\'))
                        ELSE md.status
                    END AS def_status,
                    CASE
                        WHEN cm.entitlement_snapshot_json IS NOT NULL THEN NULL
                        ELSE md.deleted_at
                    END AS def_deleted_at
             FROM client_memberships cm
             INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id
             WHERE cm.id = ?
             FOR UPDATE',
            [$id]
        );
    }

    /**
     * First blocking row for authoritative issuance (same transaction as {@see \Modules\Clients\Repositories\ClientRepository::findForUpdate} on the client).
     *
     * Blocks when calendar ranges overlap, or when an active/paused row still reaches into the proposed start
     * (covers stale active rows past ends_at until expiry pass updates status).
     *
     * @return array<string, mixed>|null
     */
    /**
     * Overlap / duplicate issuance guard with intrinsic resolved-org predicate on {@code cm} (same family as {@see findInTenantScope}).
     */
    public function findBlockingIssuanceRowInTenantScope(
        int $clientId,
        int $membershipDefinitionId,
        ?int $branchScopeId,
        string $newStartsAtYmd,
        string $newEndsAtYmd,
        int $branchContextId
    ): ?array {
        $vis = $this->clientMembershipTenantVisibility($branchContextId);

        return $this->db->fetchOne(
            'SELECT cm.id, cm.status, cm.starts_at, cm.ends_at, cm.branch_id, cm.client_id, cm.membership_definition_id
             FROM client_memberships cm
             WHERE cm.client_id = ?
               AND cm.membership_definition_id = ?
               AND cm.branch_id <=> ?
               AND (
                    (cm.starts_at <= ? AND cm.ends_at >= ?)
                    OR (
                        cm.status IN (\'active\', \'paused\')
                        AND NOT (cm.ends_at < ?)
                    )
                )
               AND (' . $vis['sql'] . ')
             ORDER BY cm.id ASC
             LIMIT 1',
            array_merge(
                [
                    $clientId,
                    $membershipDefinitionId,
                    $branchScopeId,
                    $newEndsAtYmd,
                    $newStartsAtYmd,
                    $newStartsAtYmd,
                ],
                $vis['params']
            )
        );
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'client_id', 'membership_definition_id', 'branch_id', 'starts_at', 'ends_at',
            'next_billing_at', 'last_billed_at', 'billing_state', 'billing_auto_renew_enabled',
            'status', 'notes', 'entitlement_snapshot_json', 'created_by', 'updated_by',
            'cancel_at_period_end', 'cancelled_at', 'paused_at', 'lifecycle_reason',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
