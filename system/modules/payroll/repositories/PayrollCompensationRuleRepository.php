<?php

declare(strict_types=1);

namespace Modules\Payroll\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * `payroll_compensation_rules` access. **find**, **listActive**, **listAllForBranchFilter**, and **update** append tenant
 * {@see OrganizationRepositoryScope} fragments. **create** is unscoped at repository level.
 */
final class PayrollCompensationRuleRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function find(int $id): ?array
    {
        $frag = $this->orgScope->payrollCompensationRuleBranchOrgExistsClause('pcr');
        $sql = 'SELECT pcr.* FROM payroll_compensation_rules pcr WHERE pcr.id = ?' . $frag['sql'];
        $params = array_merge([$id], $frag['params']);

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(?int $branchId, int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT pcr.* FROM payroll_compensation_rules pcr WHERE pcr.is_active = 1';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND pcr.branch_id = ?';
            $params[] = $branchId;
        }
        $frag = $this->orgScope->payrollCompensationRuleBranchOrgExistsClause('pcr');
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $sql .= ' ORDER BY pcr.priority DESC, pcr.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllForBranchFilter(?int $branchId, int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT pcr.* FROM payroll_compensation_rules pcr WHERE 1=1';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND pcr.branch_id = ?';
            $params[] = $branchId;
        }
        $frag = $this->orgScope->payrollCompensationRuleBranchOrgExistsClause('pcr');
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $sql .= ' ORDER BY pcr.is_active DESC, pcr.priority DESC, pcr.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        $this->db->insert('payroll_compensation_rules', $this->normalize($data));

        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $frag = $this->orgScope->payrollCompensationRuleBranchOrgExistsClause('pcr');
        $cols = [];
        $vals = [];
        foreach ($norm as $k => $v) {
            $cols[] = 'pcr.' . $k . ' = ?';
            $vals[] = $v;
        }
        $vals[] = $id;
        $vals = array_merge($vals, $frag['params']);
        $sql = 'UPDATE payroll_compensation_rules pcr SET ' . implode(', ', $cols) . ' WHERE pcr.id = ?' . $frag['sql'];
        $this->db->query($sql, $vals);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $allowed = [
            'branch_id', 'staff_id', 'service_id', 'service_category_id', 'rule_kind', 'name',
            'rate_percent', 'fixed_amount', 'currency', 'priority', 'is_active',
            'created_by', 'updated_by',
        ];
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        if (isset($out['is_active'])) {
            $out['is_active'] = $out['is_active'] ? 1 : 0;
        }

        return $out;
    }
}
