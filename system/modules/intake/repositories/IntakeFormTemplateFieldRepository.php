<?php

declare(strict_types=1);

namespace Modules\Intake\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * {@code intake_form_template_fields}: rows are owned by a template; **staff** paths prove parent template visibility with the same
 * catalog unions as {@see IntakeFormTemplateRepository}. **Public token** lists use {@see listByTemplateIdForPublicTokenFlow()} only
 * after the caller has bound {@code template_id} from a token-proven assignment (not HTTP tenant resolution).
 */
final class IntakeFormTemplateFieldRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Staff / tenant path: fields only when parent template is visible for the catalog operation branch.
     *
     * @return list<array<string, mixed>>
     */
    public function listByTemplateIdInTenantScopeForStaff(int $templateId, ?int $operationBranchId): array
    {
        if ($templateId <= 0) {
            return [];
        }
        $vis = $this->templateVisibility($operationBranchId);
        $sql = 'SELECT f.* FROM intake_form_template_fields f
             INNER JOIN intake_form_templates t ON t.id = f.template_id AND t.deleted_at IS NULL AND (' . $vis['sql'] . ')
             WHERE f.template_id = ?
             ORDER BY f.sort_order ASC, f.id ASC';

        return $this->db->fetchAll($sql, array_merge($vis['params'], [$templateId]));
    }

    /**
     * Public token flow: **no** resolved tenant context. Caller must use a {@code template_id} taken from an assignment row already
     * proven via {@see IntakeFormAssignmentRepository} token + graph cohesion.
     *
     * @return list<array<string, mixed>>
     */
    public function listByTemplateIdForPublicTokenFlow(int $templateId): array
    {
        if ($templateId <= 0) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT * FROM intake_form_template_fields WHERE template_id = ? ORDER BY sort_order ASC, id ASC',
            [$templateId]
        );
    }

    public function findInTenantScopeForStaff(int $fieldId, ?int $operationBranchId): ?array
    {
        if ($fieldId <= 0) {
            return null;
        }
        $vis = $this->templateVisibility($operationBranchId);
        $sql = 'SELECT f.* FROM intake_form_template_fields f
             INNER JOIN intake_form_templates t ON t.id = f.template_id AND t.deleted_at IS NULL AND (' . $vis['sql'] . ')
             WHERE f.id = ?';

        return $this->db->fetchOne($sql, array_merge($vis['params'], [$fieldId])) ?: null;
    }

    /**
     * Staff / tenant path: insert only when parent template is visible.
     *
     * @param array<string, mixed> $data
     */
    public function createInTenantScopeForStaff(array $data, ?int $operationBranchId): int
    {
        $norm = $this->normalize($data);
        $templateId = (int) ($norm['template_id'] ?? 0);
        if ($templateId <= 0) {
            throw new \InvalidArgumentException('template_id is required.');
        }
        $vis = $this->templateVisibility($operationBranchId);
        $cols = ['template_id', 'sort_order', 'field_key', 'label', 'field_type', 'required', 'options_json'];
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', $cols);
        $vals = [];
        foreach ($cols as $c) {
            $vals[] = $norm[$c] ?? null;
        }
        $sql = 'INSERT INTO intake_form_template_fields (' . $colList . ')
             SELECT ' . $placeholders . '
             FROM intake_form_templates t
             WHERE t.id = ? AND t.deleted_at IS NULL AND (' . $vis['sql'] . ')';
        $params = array_merge($vals, [$templateId], $vis['params']);
        $this->db->query($sql, $params);
        $id = (int) $this->db->lastInsertId();
        if ($id <= 0) {
            throw new \RuntimeException('Template not found or field could not be created in tenant scope.');
        }

        return $id;
    }

    public function deleteByIdInTenantScopeForStaff(int $fieldId, ?int $operationBranchId): void
    {
        if ($fieldId <= 0) {
            return;
        }
        $vis = $this->templateVisibility($operationBranchId);
        $sql = 'DELETE f FROM intake_form_template_fields f
             INNER JOIN intake_form_templates t ON t.id = f.template_id AND t.deleted_at IS NULL AND (' . $vis['sql'] . ')
             WHERE f.id = ?';
        $this->db->query($sql, array_merge($vis['params'], [$fieldId]));
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
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $allowed = [
            'template_id', 'sort_order', 'field_key', 'label', 'field_type', 'required', 'options_json',
        ];

        $row = array_intersect_key($data, array_flip($allowed));
        if (isset($row['required'])) {
            $row['required'] = (int) (bool) $row['required'];
        }
        if (isset($row['options_json']) && is_array($row['options_json'])) {
            $row['options_json'] = json_encode($row['options_json'], JSON_THROW_ON_ERROR);
        }

        return $row;
    }
}
