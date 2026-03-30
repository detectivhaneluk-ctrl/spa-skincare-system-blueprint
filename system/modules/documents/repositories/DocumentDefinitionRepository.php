<?php

declare(strict_types=1);

namespace Modules\Documents\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * {@code document_definitions}: branch-pinned **or** org-global-null ({@code branch_id IS NULL}) rows use
 * {@see OrganizationRepositoryScope::productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause()} when a concrete
 * operation branch is known (same shape as SKU visibility: that branch’s row **or** org-global template). HQ-only lists
 * use {@see OrganizationRepositoryScope::settingsBackedCatalogGlobalNullBranchOrgAnchoredSql()} (global-null org-anchored).
 * Single-row loads without a concrete branch use {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause()}
 * so HQ admins can still resolve branch-owned definitions by id where the service passes a null operation branch.
 */
final class DocumentDefinitionRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Visibility for a **single row** by id (find / update / soft-delete): strict product union when {@code $operationBranchId}
     * is set; org-wide catalog when unset (HQ / null context).
     *
     * @return array{sql: string, params: list<mixed>} Parenthetical boolean for alias {@code dd}.
     */
    private function definitionRowVisibilityParenthetical(?int $operationBranchId): array
    {
        if ($operationBranchId !== null && $operationBranchId > 0) {
            return $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('dd', $operationBranchId);
        }

        return $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('dd');
    }

    public function findInTenantScope(int $id, ?int $operationBranchId, bool $withTrashed = false): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $vis = $this->definitionRowVisibilityParenthetical($operationBranchId);
        $sql = 'SELECT dd.* FROM document_definitions dd WHERE dd.id = ?';
        if (!$withTrashed) {
            $sql .= ' AND dd.deleted_at IS NULL';
        }
        $sql .= ' AND (' . $vis['sql'] . ')';

        return $this->db->fetchOne($sql, array_merge([$id], $vis['params']));
    }

    public function findByBranchAndCode(?int $branchId, string $code): ?array
    {
        $sql = 'SELECT dd.* FROM document_definitions dd WHERE dd.deleted_at IS NULL AND dd.code = ?';
        $params = [$code];
        if ($branchId !== null && $branchId > 0) {
            $branchGate = $this->orgScope->branchIdBelongsToResolvedOrganizationExistsClause($branchId);
            $sql .= ' AND dd.branch_id = ?' . $branchGate['sql'];
            $params[] = $branchId;
            $params = array_merge($params, $branchGate['params']);
        } else {
            $global = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('dd');
            $sql .= $global['sql'];
            $params = array_merge($params, $global['params']);
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $params);
    }

    /**
     * @return list<array{id:int,branch_id:int|null,code:string,name:string,description:string|null,valid_duration_days:int|null,is_active:int}>
     */
    public function listForBranch(?int $branchId, bool $activeOnly = true): array
    {
        $sql = 'SELECT dd.id, dd.branch_id, dd.code, dd.name, dd.description, dd.valid_duration_days, dd.is_active
                FROM document_definitions dd
                WHERE dd.deleted_at IS NULL';
        $params = [];
        if ($branchId !== null && $branchId > 0) {
            $vis = $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('dd', $branchId);
            $sql .= ' AND (' . $vis['sql'] . ')';
            $params = $vis['params'];
        } else {
            $global = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('dd');
            $sql .= $global['sql'];
            $params = $global['params'];
        }
        if ($activeOnly) {
            $sql .= ' AND dd.is_active = 1';
        }
        $sql .= ' ORDER BY dd.name ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'branch_id' => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
                'description' => isset($r['description']) && $r['description'] !== '' ? (string) $r['description'] : null,
                'valid_duration_days' => $r['valid_duration_days'] !== null ? (int) $r['valid_duration_days'] : null,
                'is_active' => (int) ($r['is_active'] ?? 1),
            ];
        }

        return $out;
    }

    public function create(array $data): int
    {
        $allowed = ['branch_id', 'code', 'name', 'description', 'valid_duration_days', 'is_active'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (isset($payload['is_active'])) {
            $payload['is_active'] = $payload['is_active'] ? 1 : 0;
        }
        if (array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null && (int) $payload['branch_id'] > 0) {
            $g = $this->orgScope->branchIdBelongsToResolvedOrganizationExistsClause((int) $payload['branch_id']);
            $proof = $this->db->fetchOne('SELECT 1 AS ok WHERE 1=1' . $g['sql'], $g['params']);
            if ($proof === null) {
                throw new \DomainException('Branch is not in the current organization.');
            }
        } else {
            $live = $this->orgScope->resolvedTenantOrganizationHasLiveBranchExistsClause();
            $proof = $this->db->fetchOne('SELECT 1 AS ok WHERE 1=1' . $live['sql'], $live['params']);
            if ($proof === null) {
                throw new \DomainException('Organization has no live branch for global document definitions.');
            }
        }
        $this->db->insert('document_definitions', $payload);

        return (int) $this->db->lastInsertId();
    }

    public function updateInTenantScope(int $id, ?int $operationBranchId, array $data): void
    {
        $vis = $this->definitionRowVisibilityParenthetical($operationBranchId);
        $allowed = ['code', 'name', 'description', 'valid_duration_days', 'is_active'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (isset($payload['is_active'])) {
            $payload['is_active'] = $payload['is_active'] ? 1 : 0;
        }
        if ($payload === []) {
            return;
        }
        $sets = [];
        $params = [];
        foreach ($payload as $k => $v) {
            $sets[] = "dd.{$k} = ?";
            $params[] = $v;
        }
        $params[] = $id;
        $sql = 'UPDATE document_definitions dd SET ' . implode(', ', $sets)
            . ' WHERE dd.id = ? AND dd.deleted_at IS NULL AND (' . $vis['sql'] . ')';
        $params = array_merge($params, $vis['params']);
        $this->db->query($sql, $params);
    }

    public function softDeleteInTenantScope(int $id, ?int $operationBranchId): void
    {
        $vis = $this->definitionRowVisibilityParenthetical($operationBranchId);
        $this->db->query(
            'UPDATE document_definitions dd SET dd.deleted_at = NOW() WHERE dd.id = ? AND dd.deleted_at IS NULL AND (' . $vis['sql'] . ')',
            array_merge([$id], $vis['params'])
        );
    }
}
