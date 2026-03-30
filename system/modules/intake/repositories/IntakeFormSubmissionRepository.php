<?php

declare(strict_types=1);

namespace Modules\Intake\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Intake submissions: **staff** reads prove assignment + template + client via {@see OrganizationRepositoryScope}
 * ({@see findInTenantScopeForStaff}, {@see findSubmissionIdByAssignmentIdInTenantScopeForStaff}).
 *
 * **Public token flows** use {@see findByAssignmentIdForPublicTokenFlow} (assignment id only, after token-bound assignment proof
 * in {@see IntakeFormAssignmentRepository}) and {@see createAfterPublicTokenFlow} (insert only after the same proof chain).
 */
final class IntakeFormSubmissionRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Staff / tenant path: submission row only when linked assignment/template/client are visible for the catalog operation branch.
     */
    public function findInTenantScopeForStaff(int $id, ?int $operationBranchId): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $tVis = $this->templateVisibility($operationBranchId);
        $aVis = $this->assignmentBranchVisibility($operationBranchId);
        $sql = 'SELECT s.*
             FROM intake_form_submissions s
             INNER JOIN intake_form_assignments a ON a.id = s.assignment_id AND a.template_id = s.template_id
             INNER JOIN intake_form_templates t ON t.id = s.template_id AND t.deleted_at IS NULL AND (' . $tVis['sql'] . ')
             INNER JOIN clients c ON c.id = s.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
             WHERE s.id = ?
               AND (' . $aVis['sql'] . ')';
        $params = array_merge($tVis['params'], $cFrag['params'], [$id], $aVis['params']);

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * Public token flow: no HTTP tenant context — caller must have proven the assignment via token + graph cohesion first.
     */
    public function findByAssignmentIdForPublicTokenFlow(int $assignmentId): ?array
    {
        if ($assignmentId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM intake_form_submissions WHERE assignment_id = ?',
            [$assignmentId]
        ) ?: null;
    }

    public function findSubmissionIdByAssignmentIdInTenantScopeForStaff(int $assignmentId, ?int $operationBranchId): ?int
    {
        if ($assignmentId <= 0) {
            return null;
        }
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $tVis = $this->templateVisibility($operationBranchId);
        $aVis = $this->assignmentBranchVisibility($operationBranchId);
        $sql = 'SELECT s.id
             FROM intake_form_submissions s
             INNER JOIN intake_form_assignments a ON a.id = s.assignment_id AND a.template_id = s.template_id
             INNER JOIN intake_form_templates t ON t.id = s.template_id AND t.deleted_at IS NULL AND (' . $tVis['sql'] . ')
             INNER JOIN clients c ON c.id = s.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
             WHERE s.assignment_id = ?
               AND (' . $aVis['sql'] . ')';
        $params = array_merge($tVis['params'], $cFrag['params'], [$assignmentId], $aVis['params']);
        $row = $this->db->fetchOne($sql, $params);

        return $row ? (int) $row['id'] : null;
    }

    /**
     * Public token flow: INSERT only after assignment proven via token + graph cohesion (caller responsibility).
     *
     * @param array<string, mixed> $data
     */
    public function createAfterPublicTokenFlow(array $data): int
    {
        $row = [
            'assignment_id' => $data['assignment_id'],
            'template_id' => $data['template_id'],
            'client_id' => $data['client_id'],
            'appointment_id' => $data['appointment_id'] ?? null,
            'status' => $data['status'] ?? 'completed',
            'validation_errors_json' => isset($data['validation_errors_json'])
                ? (is_string($data['validation_errors_json']) ? $data['validation_errors_json'] : json_encode($data['validation_errors_json'], JSON_THROW_ON_ERROR))
                : null,
            'submitted_from' => $data['submitted_from'],
        ];
        $this->db->insert('intake_form_submissions', $row);

        return (int) $this->db->lastInsertId();
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
}
