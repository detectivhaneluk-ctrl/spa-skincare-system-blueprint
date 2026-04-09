<?php

declare(strict_types=1);

namespace Modules\Staff\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Sales\Services\SalesTenantScope;

/**
 * Read-only aggregates for the staff profile “performance card” (appointments, invoice revenue, payroll lines).
 * All queries are tenant org-scoped via {@see OrganizationRepositoryScope} / {@see SalesTenantScope}.
 */
final class StaffProfileStatsRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
        private SalesTenantScope $salesTenantScope,
    ) {
    }

    /**
     * Public URL for a ready primary media variant, or null.
     */
    public function getPhotoPublicUrl(?int $assetId): ?string
    {
        if ($assetId === null || $assetId < 1) {
            return null;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ma');
        $sql = 'SELECT mav.relative_path AS rel, ma.status AS st
                FROM media_assets ma
                INNER JOIN media_asset_variants mav
                    ON mav.media_asset_id = ma.id AND mav.is_primary = 1
                WHERE ma.id = ?' . $frag['sql'] . '
                LIMIT 1';
        $row = $this->db->forRead()->fetchOne($sql, array_merge([$assetId], $frag['params']));
        if ($row === null) {
            return null;
        }
        if ((string) ($row['st'] ?? '') !== 'ready') {
            return null;
        }
        $rel = trim((string) ($row['rel'] ?? ''));
        if ($rel === '') {
            return null;
        }

        return '/' . ltrim($rel, '/');
    }

    /**
     * @return array{
     *   appointments_total: int,
     *   appointments_completed: int,
     *   appointments_no_show: int,
     *   clients_distinct: int,
     *   first_appointment_at: string|null
     * }
     */
    public function getAppointmentMetrics(int $staffId): array
    {
        if ($staffId < 1) {
            return [
                'appointments_total' => 0,
                'appointments_completed' => 0,
                'appointments_no_show' => 0,
                'clients_distinct' => 0,
                'first_appointment_at' => null,
            ];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $sql = 'SELECT COUNT(*) AS appt_total,
                       COALESCE(SUM(CASE WHEN a.status = \'completed\' THEN 1 ELSE 0 END), 0) AS appt_completed,
                       COALESCE(SUM(CASE WHEN a.status = \'no_show\' THEN 1 ELSE 0 END), 0) AS appt_no_show,
                       COUNT(DISTINCT a.client_id) AS clients_distinct
                FROM appointments a
                WHERE a.deleted_at IS NULL AND a.staff_id = ?' . $frag['sql'];
        $params = array_merge([$staffId], $frag['params']);
        $row = $this->db->forRead()->fetchOne($sql, $params);
        $sqlMin = 'SELECT MIN(a.start_at) AS first_at
                   FROM appointments a
                   WHERE a.deleted_at IS NULL AND a.staff_id = ?' . $frag['sql'];
        $rowMin = $this->db->forRead()->fetchOne($sqlMin, $params);
        $first = $rowMin['first_at'] ?? null;
        $firstStr = ($first !== null && $first !== '') ? (string) $first : null;

        return [
            'appointments_total' => (int) ($row['appt_total'] ?? 0),
            'appointments_completed' => (int) ($row['appt_completed'] ?? 0),
            'appointments_no_show' => (int) ($row['appt_no_show'] ?? 0),
            'clients_distinct' => (int) ($row['clients_distinct'] ?? 0),
            'first_appointment_at' => $firstStr,
        ];
    }

    /**
     * Sum of invoice totals linked to this staff member’s appointments (non-draft / non-cancelled).
     *
     * @return array{
     *   mixed_currency: bool,
     *   scalar_total: float|null,
     *   primary_currency: string,
     *   by_currency: list<array{currency: string, total: float}>
     * }
     */
    public function getInvoiceRevenueViaAppointments(int $staffId): array
    {
        if ($staffId < 1) {
            return [
                'mixed_currency' => false,
                'scalar_total' => 0.0,
                'primary_currency' => '',
                'by_currency' => [],
            ];
        }
        $aFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $sql = 'SELECT i.currency AS cur,
                       COALESCE(SUM(i.total_amount), 0) AS total_amt
                FROM invoices i
                INNER JOIN appointments a ON a.id = i.appointment_id AND a.deleted_at IS NULL
                WHERE i.deleted_at IS NULL
                  AND i.appointment_id IS NOT NULL
                  AND a.staff_id = ?
                  AND i.status NOT IN (\'draft\', \'cancelled\')';
        $params = [$staffId];
        $iClause = $this->salesTenantScope->invoiceClause('i');
        $sql .= $iClause['sql'];
        $params = array_merge($params, $iClause['params']);
        $sql .= $aFrag['sql'];
        $params = array_merge($params, $aFrag['params']);
        $sql .= ' GROUP BY i.currency ORDER BY i.currency ASC';
        $rows = $this->db->forRead()->fetchAll($sql, $params);

        return $this->normalizeCurrencyBuckets($rows, 'total_amt');
    }

    /**
     * Sum of calculated payroll commission lines for this staff member (all runs in scope).
     *
     * @return array{
     *   mixed_currency: bool,
     *   scalar_total: float|null,
     *   primary_currency: string,
     *   by_currency: list<array{currency: string, total: float}>
     * }
     */
    public function getCommissionTotals(int $staffId): array
    {
        if ($staffId < 1) {
            return [
                'mixed_currency' => false,
                'scalar_total' => 0.0,
                'primary_currency' => '',
                'by_currency' => [],
            ];
        }
        $prFrag = $this->orgScope->payrollRunBranchOrgExistsClause('pr');
        $sql = 'SELECT pcl.currency AS cur,
                       COALESCE(SUM(pcl.calculated_amount), 0) AS total_amt
                FROM payroll_commission_lines pcl
                INNER JOIN payroll_runs pr ON pr.id = pcl.payroll_run_id
                WHERE pcl.staff_id = ?' . $prFrag['sql'] . '
                GROUP BY pcl.currency
                ORDER BY pcl.currency ASC';
        $params = array_merge([$staffId], $prFrag['params']);
        $rows = $this->db->forRead()->fetchAll($sql, $params);

        return $this->normalizeCurrencyBuckets($rows, 'total_amt');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{
     *   mixed_currency: bool,
     *   scalar_total: float|null,
     *   primary_currency: string,
     *   by_currency: list<array{currency: string, total: float}>
     * }
     */
    private function normalizeCurrencyBuckets(array $rows, string $amountKey): array
    {
        $byCurrency = [];
        foreach ($rows as $r) {
            $cur = strtoupper(trim((string) ($r['cur'] ?? '')));
            $byCurrency[] = [
                'currency' => $cur,
                'total' => round((float) ($r[$amountKey] ?? 0), 2),
            ];
        }
        $keys = [];
        foreach ($byCurrency as $b) {
            $keys[$b['currency']] = true;
        }
        $mixed = count($keys) > 1;
        $scalar = null;
        if (!$mixed) {
            $scalar = 0.0;
            foreach ($byCurrency as $b) {
                $scalar += (float) $b['total'];
            }
            $scalar = round($scalar, 2);
        }
        $primary = $byCurrency[0]['currency'] ?? '';

        return [
            'mixed_currency' => $mixed,
            'scalar_total' => $scalar,
            'primary_currency' => $primary,
            'by_currency' => $byCurrency,
        ];
    }
}
