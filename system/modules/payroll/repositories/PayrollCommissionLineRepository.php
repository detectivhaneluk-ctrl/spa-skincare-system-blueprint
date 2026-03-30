<?php

declare(strict_types=1);

namespace Modules\Payroll\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * `payroll_commission_lines` access. **deleteByRunId**, **listByRunId**, **allocatedSourceRefsExcludingRun** join
 * `payroll_runs` and append tenant org EXISTS fragments. **insert** is unscoped.
 */
final class PayrollCommissionLineRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function deleteByRunId(int $runId): void
    {
        $frag = $this->orgScope->payrollRunBranchOrgExistsClause('pr');
        $sql = 'DELETE pcl FROM payroll_commission_lines pcl
            INNER JOIN payroll_runs pr ON pr.id = pcl.payroll_run_id
            WHERE pcl.payroll_run_id = ?' . $frag['sql'];
        $this->db->query($sql, array_merge([$runId], $frag['params']));
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $this->db->insert('payroll_commission_lines', $this->normalize($row));

        return $this->db->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByRunId(int $runId): array
    {
        $frag = $this->orgScope->payrollRunBranchOrgExistsClause('pr');
        $sql = 'SELECT pcl.*, s.first_name AS staff_first_name, s.last_name AS staff_last_name
             FROM payroll_commission_lines pcl
             INNER JOIN staff s ON s.id = pcl.staff_id
             INNER JOIN payroll_runs pr ON pr.id = pcl.payroll_run_id
             WHERE pcl.payroll_run_id = ?' . $frag['sql'] . '
             ORDER BY pcl.staff_id, pcl.source_kind, pcl.id';
        $params = array_merge([$runId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array<string, array<int, true>> kind => set of source_ref
     */
    public function allocatedSourceRefsExcludingRun(int $excludeRunId): array
    {
        $frag = $this->orgScope->payrollRunBranchOrgExistsClause('pr');
        $sql = 'SELECT pcl.source_kind, pcl.source_ref
             FROM payroll_commission_lines pcl
             INNER JOIN payroll_runs pr ON pr.id = pcl.payroll_run_id
             WHERE pr.id != ?
               AND pr.status IN (\'calculated\', \'locked\', \'settled\')' . $frag['sql'];
        $params = array_merge([$excludeRunId], $frag['params']);
        $rows = $this->db->fetchAll($sql, $params);
        $out = [
            'service_invoice_item' => [],
            'appointment_fixed' => [],
        ];
        foreach ($rows as $r) {
            $kind = (string) ($r['source_kind'] ?? '');
            $ref = (int) ($r['source_ref'] ?? 0);
            if ($ref < 1 || !isset($out[$kind])) {
                continue;
            }
            $out[$kind][$ref] = true;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $allowed = [
            'payroll_run_id', 'compensation_rule_id', 'source_kind', 'source_ref',
            'appointment_id', 'invoice_id', 'invoice_item_id', 'staff_id', 'branch_id',
            'base_amount', 'currency', 'rate_percent', 'rule_fixed_amount', 'calculated_amount',
            'derivation_json',
        ];
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $row)) {
                $out[$k] = $row[$k];
            }
        }
        if (array_key_exists('derivation_json', $out) && $out['derivation_json'] !== null && !is_string($out['derivation_json'])) {
            $out['derivation_json'] = json_encode($out['derivation_json'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $out;
    }
}
