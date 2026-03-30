<?php

declare(strict_types=1);

namespace Modules\Intake\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Value rows for submissions. Staff reads use {@see listBySubmissionIdInTenantScopeForStaff}; public INSERT uses {@see insert}
 * only immediately after a submission created in the same transaction (token flow).
 */
final class IntakeFormSubmissionValueRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function insert(int $submissionId, string $fieldKey, ?string $valueText): void
    {
        $this->db->insert('intake_form_submission_values', [
            'submission_id' => $submissionId,
            'field_key' => $fieldKey,
            'value_text' => $valueText,
        ]);
    }

    /**
     * Staff / tenant path: values only when the parent submission is visible like {@see IntakeFormSubmissionRepository::findInTenantScopeForStaff()}.
     *
     * @return list<array<string, mixed>>
     */
    public function listBySubmissionIdInTenantScopeForStaff(int $submissionId, ?int $operationBranchId): array
    {
        if ($submissionId <= 0) {
            return [];
        }
        $cFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $tVis = $this->templateVisibility($operationBranchId);
        $aVis = $this->assignmentBranchVisibility($operationBranchId);
        $sql = 'SELECT v.*
             FROM intake_form_submission_values v
             INNER JOIN intake_form_submissions s ON s.id = v.submission_id
             INNER JOIN intake_form_assignments a ON a.id = s.assignment_id AND a.template_id = s.template_id
             INNER JOIN intake_form_templates t ON t.id = s.template_id AND t.deleted_at IS NULL AND (' . $tVis['sql'] . ')
             INNER JOIN clients c ON c.id = s.client_id AND c.deleted_at IS NULL' . $cFrag['sql'] . '
             WHERE v.submission_id = ?
               AND (' . $aVis['sql'] . ')
             ORDER BY v.field_key ASC';
        $params = array_merge($tVis['params'], $cFrag['params'], [$submissionId], $aVis['params']);

        return $this->db->fetchAll($sql, $params);
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
