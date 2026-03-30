<?php

declare(strict_types=1);

namespace Modules\Intake\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * {@code intake_form_templates}: nullable {@code branch_id} uses the same **catalog** unions as document definitions /
 * product SKUs — {@see OrganizationRepositoryScope::productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause()} when
 * an operation branch is known; {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause()}
 * for tenant-wide staff lists when the UI passes no branch filter.
 */
final class IntakeFormTemplateRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
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

    public function findInTenantScopeForStaff(int $id, ?int $operationBranchId, bool $withTrashed = false): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $vis = $this->templateVisibility($operationBranchId);
        $sql = 'SELECT t.* FROM intake_form_templates t WHERE t.id = ?';
        if (!$withTrashed) {
            $sql .= ' AND t.deleted_at IS NULL';
        }
        $sql .= ' AND (' . $vis['sql'] . ')';

        return $this->db->fetchOne($sql, array_merge([$id], $vis['params']));
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
        $vis = $this->templateVisibility($branchForVisibility);
        $sql = 'SELECT * FROM intake_form_templates t WHERE t.deleted_at IS NULL AND (' . $vis['sql'] . ')';
        $params = $vis['params'];
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $sql .= ' AND t.is_active = ?';
            $params[] = (int) (bool) $filters['is_active'];
        }
        $sql .= ' ORDER BY t.name ASC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);

        return $this->db->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        $this->db->insert('intake_form_templates', $this->normalizeTemplate($data));

        return (int) $this->db->lastInsertId();
    }

    public function updateInTenantScopeForStaff(int $id, ?int $operationBranchId, array $data): void
    {
        $vis = $this->templateVisibility($operationBranchId);
        $norm = $this->normalizeTemplate($data);
        if ($norm === []) {
            return;
        }
        $cols = array_map(static fn (string $k): string => "t.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $sql = 'UPDATE intake_form_templates t SET ' . implode(', ', $cols)
            . ' WHERE t.id = ? AND t.deleted_at IS NULL AND (' . $vis['sql'] . ')';
        $vals = array_merge($vals, $vis['params']);
        $this->db->query($sql, $vals);
    }

    public function softDeleteInTenantScopeForStaff(int $id, ?int $operationBranchId): void
    {
        $vis = $this->templateVisibility($operationBranchId);
        $this->db->query(
            'UPDATE intake_form_templates t SET t.deleted_at = NOW() WHERE t.id = ? AND t.deleted_at IS NULL AND (' . $vis['sql'] . ')',
            array_merge([$id], $vis['params'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeTemplate(array $data): array
    {
        $allowed = ['branch_id', 'name', 'description', 'is_active', 'required_before_appointment', 'created_by', 'updated_by'];
        $row = array_intersect_key($data, array_flip($allowed));
        if (isset($row['is_active'])) {
            $row['is_active'] = (int) (bool) $row['is_active'];
        }
        if (isset($row['required_before_appointment'])) {
            $row['required_before_appointment'] = (int) (bool) $row['required_before_appointment'];
        }

        return $row;
    }
}
