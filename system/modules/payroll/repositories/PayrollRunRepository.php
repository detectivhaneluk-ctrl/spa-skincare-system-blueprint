<?php

declare(strict_types=1);

namespace Modules\Payroll\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * `payroll_runs` access. **find**, **listForBranch**, **listRecent**, **update**, and **delete** append tenant
 * {@see OrganizationRepositoryScope} fragments (fail-closed). **create** is unscoped at repository level.
 */
final class PayrollRunRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function find(int $id): ?array
    {
        $frag = $this->orgScope->payrollRunBranchOrgExistsClause('pr');
        $sql = 'SELECT pr.* FROM payroll_runs pr WHERE pr.id = ?' . $frag['sql'];
        $params = array_merge([$id], $frag['params']);

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForBranch(int $branchId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $frag = $this->orgScope->payrollRunBranchOrgExistsClause('pr');
        $sql = 'SELECT pr.* FROM payroll_runs pr WHERE pr.branch_id = ?' . $frag['sql']
            . ' ORDER BY pr.period_start DESC, pr.id DESC LIMIT ? OFFSET ?';
        $params = array_merge([$branchId], $frag['params'], [$limit, $offset]);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * When branch filter is null, lists all runs in the resolved organization (via branch/org EXISTS).
     *
     * @return list<array<string, mixed>>
     */
    public function listRecent(?int $branchId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        if ($branchId !== null) {
            return $this->listForBranch($branchId, $limit, $offset);
        }
        $frag = $this->orgScope->payrollRunBranchOrgExistsClause('pr');
        $sql = 'SELECT pr.* FROM payroll_runs pr WHERE 1=1' . $frag['sql']
            . ' ORDER BY pr.period_start DESC, pr.id DESC LIMIT ? OFFSET ?';
        $params = array_merge($frag['params'], [$limit, $offset]);

        return $this->db->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        $this->db->insert('payroll_runs', $this->normalizeRun($data));

        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalizeRun($data);
        if ($norm === []) {
            return;
        }
        $frag = $this->orgScope->payrollRunBranchOrgExistsClause('pr');
        $cols = [];
        $vals = [];
        foreach ($norm as $k => $v) {
            $cols[] = 'pr.' . $k . ' = ?';
            $vals[] = $v;
        }
        $vals[] = $id;
        $vals = array_merge($vals, $frag['params']);
        $sql = 'UPDATE payroll_runs pr SET ' . implode(', ', $cols) . ' WHERE pr.id = ?' . $frag['sql'];
        $this->db->query($sql, $vals);
    }

    public function delete(int $id): void
    {
        $frag = $this->orgScope->payrollRunBranchOrgExistsClause('pr');
        $sql = 'DELETE pr FROM payroll_runs pr WHERE pr.id = ?' . $frag['sql'];
        $this->db->query($sql, array_merge([$id], $frag['params']));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeRun(array $data): array
    {
        $allowed = [
            'branch_id', 'period_start', 'period_end', 'status', 'settled_at', 'settled_by',
            'notes', 'created_by', 'updated_by',
        ];
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }

        return $out;
    }
}
