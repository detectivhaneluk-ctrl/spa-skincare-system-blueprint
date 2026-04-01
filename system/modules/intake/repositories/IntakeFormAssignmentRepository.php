<?php

declare(strict_types=1);

namespace Modules\Intake\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Staff paths use branch-derived {@see OrganizationRepositoryScope} fragments.
 *
 * **Public token paths** ({@see findByTokenHashWithPublicGraphOrgCohesion}, {@see findByAssignmentIdAndTokenHashWithPublicGraphOrgCohesion}):
 * there is **no** HTTP tenant context. Reads prove **single active-organization structural cohesion** among assignment, template, client,
 * and optional appointment branch FKs, plus a **fallback anchor** when all branch columns are NULL (client tied to exactly one active org
 * via appointment or invoice). This is **not** the same as resolved-tenant {@see OrganizationRepositoryScope::clientProfileOrgMembershipExistsClause()}.
 */
final class IntakeFormAssignmentRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Staff / tenant path: assignment + joined template + client are org-scoped.
     */
    public function findInTenantScopeForStaff(int $id, ?int $operationBranchId): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $tVis = $this->templateVisibility($operationBranchId);
        $sql = 'SELECT a.*, t.name AS template_name, t.is_active AS template_active,
                    t.required_before_appointment AS template_required_before_appt
             FROM intake_form_assignments a
             INNER JOIN intake_form_templates t ON t.id = a.template_id AND t.deleted_at IS NULL AND (' . $tVis['sql'] . ')
             INNER JOIN clients c ON c.id = a.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
             WHERE a.id = ?';
        $params = array_merge($tVis['params'], $cFrag['params'], [$id]);

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * Anonymous public intake: first load by secret token. Cohesion predicate is {@see self::publicGraphOrgCohesionSql()}.
     */
    public function findByTokenHashWithPublicGraphOrgCohesion(string $tokenHash): ?array
    {
        $sql = $this->publicAssignmentSelectWithTemplateJoin()
            . ' WHERE a.token_hash = ?' . $this->publicGraphOrgCohesionSql();

        return $this->db->fetchOne($sql, [$tokenHash]) ?: null;
    }

    /**
     * Anonymous public intake: reload / submit after initial token proof — requires **same** token hash as original lookup.
     */
    public function findByAssignmentIdAndTokenHashWithPublicGraphOrgCohesion(int $id, string $tokenHash): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $sql = $this->publicAssignmentSelectWithTemplateJoin()
            . ' WHERE a.id = ? AND a.token_hash = ?' . $this->publicGraphOrgCohesionSql();

        return $this->db->fetchOne($sql, [$id, $tokenHash]) ?: null;
    }

    /**
     * Anonymous public intake: UPDATE only when id + token_hash still match and cohesion holds.
     *
     * @param array<string, mixed> $data
     */
    public function updateColumnsWhereIdAndTokenHashForPublicTokenFlow(int $id, string $tokenHash, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $sets = [];
        $setParams = [];
        foreach ($norm as $k => $v) {
            $sets[] = "a.{$k} = ?";
            $setParams[] = $v;
        }
        $sql = 'UPDATE intake_form_assignments a
             INNER JOIN intake_form_templates t ON t.id = a.template_id AND t.deleted_at IS NULL
             INNER JOIN clients c ON c.id = a.client_id AND c.deleted_at IS NULL
             SET ' . implode(', ', $sets) . '
             WHERE a.id = ? AND a.token_hash = ?' . $this->publicGraphOrgCohesionSql();
        $params = array_merge($setParams, [$id, $tokenHash]);
        $this->db->query($sql, $params);
    }

    /**
     * Staff: UPDATE only for rows visible in {@see findInTenantScopeForStaff()}.
     *
     * @param array<string, mixed> $data
     */
    public function updateColumnsInTenantScopeForStaff(int $id, ?int $operationBranchId, array $data): void
    {
        if ($id <= 0) {
            return;
        }
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $tVis = $this->templateVisibility($operationBranchId);
        $sets = [];
        $setParams = [];
        foreach ($norm as $k => $v) {
            $sets[] = "a.{$k} = ?";
            $setParams[] = $v;
        }
        $sql = 'UPDATE intake_form_assignments a
             INNER JOIN intake_form_templates t ON t.id = a.template_id AND t.deleted_at IS NULL AND (' . $tVis['sql'] . ')
             INNER JOIN clients c ON c.id = a.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
             SET ' . implode(', ', $sets) . '
             WHERE a.id = ?';
        $params = array_merge($setParams, $tVis['params'], $cFrag['params'], [$id]);
        $this->db->query($sql, $params);
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function templateVisibility(?int $operationBranchId): array
    {
        if ($operationBranchId !== null && $operationBranchId > 0) {
            return $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('t', $operationBranchId);
        }

        return $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('t');
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function assignmentBranchVisibility(?int $operationBranchId): array
    {
        if ($operationBranchId !== null && $operationBranchId > 0) {
            return $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('a', $operationBranchId);
        }

        return $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('a');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInTenantScopeForStaff(array $filters = [], ?int $operationBranchId = null, int $limit = 100, int $offset = 0): array
    {
        $branchForVisibility = null;
        if (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $branchForVisibility = (int) $filters['branch_id'];
        } else {
            $branchForVisibility = $operationBranchId;
        }
        $tVis = $this->templateVisibility($branchForVisibility);
        $aVis = $this->assignmentBranchVisibility($branchForVisibility);
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $sql = 'SELECT a.*, t.name AS template_name, c.first_name AS client_first_name, c.last_name AS client_last_name,
                       s.id AS submission_id
                FROM intake_form_assignments a
                INNER JOIN intake_form_templates t ON t.id = a.template_id AND t.deleted_at IS NULL AND (' . $tVis['sql'] . ')
                INNER JOIN clients c ON c.id = a.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
                LEFT JOIN intake_form_submissions s ON s.assignment_id = a.id
                WHERE 1=1 AND (' . $aVis['sql'] . ')';
        $params = array_merge($tVis['params'], $cFrag['params'], $aVis['params']);
        if (!empty($filters['client_id'])) {
            $sql .= ' AND a.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (!empty($filters['appointment_id'])) {
            $sql .= ' AND a.appointment_id = ?';
            $params[] = (int) $filters['appointment_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND a.status = ?';
            $params[] = $filters['status'];
        }
        $sql .= ' ORDER BY a.assigned_at DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Staff / tenant path: counts open required-before-appointment assignments for a specific appointment row.
     * Proof root: joined {@code appointments ap} must satisfy {@see OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause()}
     * on {@code ap.branch_id}; assignment/template/client visibility matches {@see listInTenantScopeForStaff()} for the given catalog operation branch
     * (appointment {@code branch_id} when set and positive, otherwise the caller’s branch-context branch for catalog union semantics).
     */
    public function countIncompleteRequiredPriorForAppointmentInTenantScope(int $appointmentId, ?int $catalogOperationBranchId): int
    {
        if ($appointmentId <= 0) {
            return 0;
        }
        $apFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ap');
        $tVis = $this->templateVisibility($catalogOperationBranchId);
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $aVis = $this->assignmentBranchVisibility($catalogOperationBranchId);
        $sql = 'SELECT COUNT(*) AS c
             FROM intake_form_assignments a
             INNER JOIN appointments ap ON ap.id = a.appointment_id AND ap.deleted_at IS NULL
             INNER JOIN intake_form_templates t ON t.id = a.template_id AND t.deleted_at IS NULL AND (' . $tVis['sql'] . ')
             INNER JOIN clients c ON c.id = a.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
             WHERE a.appointment_id = ?' . $apFrag['sql'] . '
               AND t.required_before_appointment = 1
               AND t.is_active = 1
               AND a.status IN (\'pending\',\'opened\')
               AND (' . $aVis['sql'] . ')';
        $params = array_merge(
            $tVis['params'],
            $cFrag['params'],
            [$appointmentId],
            $apFrag['params'],
            $aVis['params']
        );
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $this->db->insert('intake_form_assignments', $this->normalize($data));

        return (int) $this->db->lastInsertId();
    }

    private function publicAssignmentSelectWithTemplateJoin(): string
    {
        return 'SELECT a.*, t.name AS template_name, t.is_active AS template_active,
                    t.required_before_appointment AS template_required_before_appt
             FROM intake_form_assignments a
             INNER JOIN intake_form_templates t ON t.id = a.template_id AND t.deleted_at IS NULL
             INNER JOIN clients c ON c.id = a.client_id AND c.deleted_at IS NULL AND c.merged_into_client_id IS NULL';
    }

    /**
 * No request-scoped organization id: require all non-null branch FKs among assignment/template/client/(appointment)
 * to resolve to at most one **active** {@code organizations.id}, and require either a concrete branch anchor on the graph or
 * exactly one active organization anchor via the client’s appointment/invoice rows.
     */
    private function publicGraphOrgCohesionSql(): string
    {
        return <<<'SQL'

 AND (
  (
    SELECT COUNT(DISTINCT br.organization_id)
    FROM (
      SELECT a.branch_id AS bid UNION ALL SELECT t.branch_id UNION ALL SELECT c.branch_id
      UNION ALL SELECT ap.branch_id FROM appointments ap WHERE ap.id = a.appointment_id AND ap.deleted_at IS NULL
    ) q
    INNER JOIN branches br ON br.id = q.bid AND br.deleted_at IS NULL
    INNER JOIN organizations org ON org.id = br.organization_id AND org.deleted_at IS NULL AND org.suspended_at IS NULL
    WHERE q.bid IS NOT NULL
  ) <= 1
)
AND (
  COALESCE(
    a.branch_id,
    t.branch_id,
    c.branch_id,
    (SELECT ap2.branch_id FROM appointments ap2 WHERE ap2.id = a.appointment_id AND ap2.deleted_at IS NULL LIMIT 1)
  ) IS NOT NULL
  OR (
    SELECT COUNT(DISTINCT hist.organization_id)
    FROM (
      SELECT bx.organization_id
      FROM appointments apx
      INNER JOIN branches bx ON bx.id = apx.branch_id AND bx.deleted_at IS NULL
      INNER JOIN organizations ox ON ox.id = bx.organization_id AND ox.deleted_at IS NULL AND ox.suspended_at IS NULL
      WHERE apx.client_id = c.id AND apx.deleted_at IS NULL AND apx.branch_id IS NOT NULL
      UNION
      SELECT bix.organization_id
      FROM invoices inv
      INNER JOIN branches bix ON bix.id = inv.branch_id AND bix.deleted_at IS NULL
      INNER JOIN organizations oix ON oix.id = bix.organization_id AND oix.deleted_at IS NULL AND oix.suspended_at IS NULL
      WHERE inv.client_id = c.id AND inv.deleted_at IS NULL AND inv.branch_id IS NOT NULL
    ) hist
  ) = 1
)
SQL;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $allowed = [
            'template_id', 'client_id', 'appointment_id', 'branch_id', 'status', 'token_hash', 'token_expires_at',
            'assigned_by', 'opened_at', 'completed_at', 'cancelled_at', 'cancel_reason',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }
}
