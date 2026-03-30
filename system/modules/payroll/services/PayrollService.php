<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Core\Organization\OrganizationScopedBranchAssert;
use Modules\Payroll\Repositories\PayrollCommissionLineRepository;
use Modules\Payroll\Repositories\PayrollCompensationRuleRepository;
use Modules\Payroll\Repositories\PayrollRunRepository;
use Modules\Sales\Services\SalesTenantScope;

/**
 * Central payroll / commission logic: eligibility from paid invoices + completed appointments + appointment-linked service lines;
 * rules from {@see payroll_compensation_rules}; duplicate prevention via lines on finalized sibling runs.
 * Invoice-plane eligibility uses {@see SalesTenantScope::invoiceClause()} on {@code invoices i} (same tenant data-plane as Sales).
 */
final class PayrollService
{
    public const RULE_PERCENT = 'percent_service_line';

    public const RULE_FIXED = 'fixed_per_appointment';

    public function __construct(
        private Database $db,
        private BranchContext $branchContext,
        private OrganizationContext $organizationContext,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
        private PayrollRunRepository $runs,
        private PayrollCommissionLineRepository $lines,
        private PayrollCompensationRuleRepository $rules,
        private SalesTenantScope $salesTenantScope,
    ) {
    }

    public function createRun(int $branchId, string $periodStart, string $periodEnd, ?string $notes, ?int $userId): int
    {
        $this->branchContext->assertBranchMatchStrict($branchId);
        if ($this->organizationContext->getCurrentOrganizationId() !== null) {
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($branchId);
        }
        $this->assertValidPeriod($periodStart, $periodEnd);

        return $this->runs->create([
            'branch_id' => $branchId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'draft',
            'notes' => $notes,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    public function deleteDraftRun(int $runId): void
    {
        $run = $this->runs->find($runId);
        if (!$run) {
            throw new \DomainException('Payroll run not found.');
        }
        $this->branchContext->assertBranchMatchStrict((int) $run['branch_id']);
        if (($run['status'] ?? '') !== 'draft') {
            throw new \DomainException('Only draft payroll runs can be deleted.');
        }
        $this->lines->deleteByRunId($runId);
        $this->runs->delete($runId);
    }

    /**
     * Recompute commission lines from authoritative events; sets status to calculated.
     */
    public function calculateRun(int $runId, ?int $userId): void
    {
        $run = $this->runs->find($runId);
        if (!$run) {
            throw new \DomainException('Payroll run not found.');
        }
        $this->branchContext->assertBranchMatchStrict((int) $run['branch_id']);
        if (($run['status'] ?? '') !== 'draft') {
            throw new \DomainException('Only draft runs can be calculated.');
        }

        $branchId = (int) $run['branch_id'];
        $pStart = (string) $run['period_start'];
        $pEnd = (string) $run['period_end'];

        $allocated = $this->lines->allocatedSourceRefsExcludingRun($runId);
        $ruleRows = $this->rules->listActive($branchId, 500, 0);

        $pdo = $this->db->connection();
        $pdo->beginTransaction();
        try {
            $this->lines->deleteByRunId($runId);

            $events = $this->fetchEligibleServiceLineEvents($branchId, $pStart, $pEnd);
            foreach ($events as $ev) {
                $itemId = (int) $ev['invoice_item_id'];
                if (isset($allocated['service_invoice_item'][$itemId])) {
                    continue;
                }
                $rule = $this->pickRule($ruleRows, self::RULE_PERCENT, $ev);
                if ($rule === null) {
                    continue;
                }
                $base = (float) $ev['base_amount'];
                $rate = (float) ($rule['rate_percent'] ?? 0);
                $amount = round($base * $rate / 100.0, 2);
                $this->lines->insert([
                    'payroll_run_id' => $runId,
                    'compensation_rule_id' => (int) $rule['id'],
                    'source_kind' => 'service_invoice_item',
                    'source_ref' => $itemId,
                    'appointment_id' => (int) $ev['appointment_id'],
                    'invoice_id' => (int) $ev['invoice_id'],
                    'invoice_item_id' => $itemId,
                    'staff_id' => (int) $ev['staff_id'],
                    'branch_id' => $branchId,
                    'base_amount' => $base,
                    'currency' => (string) $ev['currency'],
                    'rate_percent' => $rate,
                    'rule_fixed_amount' => null,
                    'calculated_amount' => $amount,
                    'derivation_json' => $this->derivationPayload($ev, $rule, 'percent_service_line', [
                        'formula' => 'line_total * rate_percent / 100',
                    ]),
                ]);
                $allocated['service_invoice_item'][$itemId] = true;
            }

            $seenApptFixed = [];
            foreach ($events as $ev) {
                $apptId = (int) $ev['appointment_id'];
                if (isset($seenApptFixed[$apptId])) {
                    continue;
                }
                $seenApptFixed[$apptId] = true;
                if (isset($allocated['appointment_fixed'][$apptId])) {
                    continue;
                }
                $rule = $this->pickRule($ruleRows, self::RULE_FIXED, $ev);
                if ($rule === null) {
                    continue;
                }
                $currency = (string) $ev['currency'];
                $ruleCur = (string) ($rule['currency'] ?? '');
                if ($ruleCur === '' || strtoupper($ruleCur) !== strtoupper($currency)) {
                    continue;
                }
                $fixed = (float) ($rule['fixed_amount'] ?? 0);
                $this->lines->insert([
                    'payroll_run_id' => $runId,
                    'compensation_rule_id' => (int) $rule['id'],
                    'source_kind' => 'appointment_fixed',
                    'source_ref' => $apptId,
                    'appointment_id' => $apptId,
                    'invoice_id' => (int) $ev['invoice_id'],
                    'invoice_item_id' => (int) $ev['invoice_item_id'],
                    'staff_id' => (int) $ev['staff_id'],
                    'branch_id' => $branchId,
                    'base_amount' => 0.0,
                    'currency' => $currency,
                    'rate_percent' => null,
                    'rule_fixed_amount' => $fixed,
                    'calculated_amount' => round($fixed, 2),
                    'derivation_json' => $this->derivationPayload($ev, $rule, 'fixed_per_appointment', [
                        'formula' => 'fixed_amount from rule',
                    ]),
                ]);
                $allocated['appointment_fixed'][$apptId] = true;
            }

            $this->runs->update($runId, [
                'status' => 'calculated',
                'updated_by' => $userId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function reopenRunToDraft(int $runId, ?int $userId): void
    {
        $run = $this->runs->find($runId);
        if (!$run) {
            throw new \DomainException('Payroll run not found.');
        }
        $this->branchContext->assertBranchMatchStrict((int) $run['branch_id']);
        if (($run['status'] ?? '') !== 'calculated') {
            throw new \DomainException('Only calculated runs can be reopened to draft.');
        }
        $pdo = $this->db->connection();
        $pdo->beginTransaction();
        try {
            $this->lines->deleteByRunId($runId);
            $this->runs->update($runId, [
                'status' => 'draft',
                'updated_by' => $userId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function lockRun(int $runId, ?int $userId): void
    {
        $run = $this->runs->find($runId);
        if (!$run) {
            throw new \DomainException('Payroll run not found.');
        }
        $this->branchContext->assertBranchMatchStrict((int) $run['branch_id']);
        if (($run['status'] ?? '') !== 'calculated') {
            throw new \DomainException('Only calculated runs can be locked.');
        }
        $this->runs->update($runId, [
            'status' => 'locked',
            'updated_by' => $userId,
        ]);
    }

    /**
     * Marks external payout as recorded; does not move money.
     */
    public function settleRun(int $runId, ?int $userId): void
    {
        $run = $this->runs->find($runId);
        if (!$run) {
            throw new \DomainException('Payroll run not found.');
        }
        $this->branchContext->assertBranchMatchStrict((int) $run['branch_id']);
        if (($run['status'] ?? '') !== 'locked') {
            throw new \DomainException('Only locked runs can be settled.');
        }
        $this->runs->update($runId, [
            'status' => 'settled',
            'settled_at' => date('Y-m-d H:i:s'),
            'settled_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchEligibleServiceLineEvents(int $branchId, string $periodStart, string $periodEnd): array
    {
        $iScope = $this->salesTenantScope->invoiceClause('i');
        $sql = 'SELECT
                    ii.id AS invoice_item_id,
                    i.id AS invoice_id,
                    i.currency,
                    ii.line_total AS base_amount,
                    a.id AS appointment_id,
                    a.staff_id,
                    a.service_id,
                    s.category_id AS service_category_id,
                    COALESCE(i.branch_id, a.branch_id) AS effective_branch_id,
                    pm.earning_moment AS earning_moment
                FROM invoices i
                INNER JOIN appointments a ON a.id = i.appointment_id AND a.deleted_at IS NULL
                INNER JOIN invoice_items ii ON ii.invoice_id = i.id
                    AND ii.item_type = \'service\'
                    AND a.service_id IS NOT NULL
                    AND ii.source_id = a.service_id
                LEFT JOIN services s ON s.id = a.service_id AND s.deleted_at IS NULL
                INNER JOIN (
                    SELECT invoice_id, MAX(COALESCE(paid_at, created_at)) AS earning_moment
                    FROM payments
                    WHERE status = \'completed\'
                    GROUP BY invoice_id
                ) pm ON pm.invoice_id = i.id
                WHERE i.deleted_at IS NULL'
            . $iScope['sql']
            . '
                  AND i.status = \'paid\'
                  AND a.status = \'completed\'
                  AND a.staff_id IS NOT NULL
                  AND COALESCE(i.branch_id, a.branch_id) = ?
                  AND DATE(pm.earning_moment) >= ?
                  AND DATE(pm.earning_moment) <= ?';

        $params = array_merge($iScope['params'], [$branchId, $periodStart, $periodEnd]);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param list<array<string, mixed>> $ruleRows
     * @param array<string, mixed> $eventCtx
     * @return array<string, mixed>|null
     */
    private function pickRule(array $ruleRows, string $ruleKind, array $eventCtx): ?array
    {
        $branchId = (int) $eventCtx['effective_branch_id'];
        $staffId = (int) $eventCtx['staff_id'];
        $serviceId = isset($eventCtx['service_id']) && $eventCtx['service_id'] !== null && $eventCtx['service_id'] !== ''
            ? (int) $eventCtx['service_id']
            : null;
        $categoryId = isset($eventCtx['service_category_id']) && $eventCtx['service_category_id'] !== null && $eventCtx['service_category_id'] !== ''
            ? (int) $eventCtx['service_category_id']
            : null;

        $candidates = [];
        foreach ($ruleRows as $rule) {
            if (($rule['rule_kind'] ?? '') !== $ruleKind) {
                continue;
            }
            $rb = $rule['branch_id'] ?? null;
            if ($rb !== null && (int) $rb !== $branchId) {
                continue;
            }
            $rs = $rule['staff_id'] ?? null;
            if ($rs !== null && (int) $rs !== $staffId) {
                continue;
            }
            $rvc = $rule['service_id'] ?? null;
            if ($rvc !== null && ($serviceId === null || (int) $rvc !== $serviceId)) {
                continue;
            }
            $rcat = $rule['service_category_id'] ?? null;
            if ($rcat !== null && ($categoryId === null || (int) $rcat !== $categoryId)) {
                continue;
            }
            if ($ruleKind === self::RULE_PERCENT) {
                if (!isset($rule['rate_percent']) || (float) $rule['rate_percent'] <= 0) {
                    continue;
                }
            } else {
                if (!isset($rule['fixed_amount']) || (float) $rule['fixed_amount'] <= 0 || empty($rule['currency'])) {
                    continue;
                }
            }
            $candidates[] = $rule;
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, function (array $a, array $b): int {
            $sa = $this->ruleSpecificityScore($a);
            $sb = $this->ruleSpecificityScore($b);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            $pa = (int) ($a['priority'] ?? 0);
            $pb = (int) ($b['priority'] ?? 0);
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }

            return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
        });

        return $candidates[0];
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function ruleSpecificityScore(array $rule): int
    {
        $score = 0;
        if (isset($rule['branch_id']) && $rule['branch_id'] !== null && $rule['branch_id'] !== '') {
            $score += 8;
        }
        if (isset($rule['staff_id']) && $rule['staff_id'] !== null && $rule['staff_id'] !== '') {
            $score += 4;
        }
        if (isset($rule['service_category_id']) && $rule['service_category_id'] !== null && $rule['service_category_id'] !== '') {
            $score += 2;
        }
        if (isset($rule['service_id']) && $rule['service_id'] !== null && $rule['service_id'] !== '') {
            $score += 2;
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $ev
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function derivationPayload(array $ev, array $rule, string $kind, array $extra): array
    {
        return array_merge([
            'eligibility' => [
                'invoice_status' => 'paid',
                'appointment_status' => 'completed',
                'earning_moment' => $ev['earning_moment'] ?? null,
                'effective_branch_id' => isset($ev['effective_branch_id']) ? (int) $ev['effective_branch_id'] : null,
                'source' => 'payments.completed MAX(COALESCE(paid_at, created_at)) per invoice; invoice must be paid; appointment linked; service line source_id equals appointment.service_id',
            ],
            'rule_kind' => $kind,
            'compensation_rule_id' => (int) ($rule['id'] ?? 0),
            'rule_snapshot' => [
                'branch_id' => $rule['branch_id'] ?? null,
                'staff_id' => $rule['staff_id'] ?? null,
                'service_id' => $rule['service_id'] ?? null,
                'service_category_id' => $rule['service_category_id'] ?? null,
                'priority' => $rule['priority'] ?? 0,
            ],
        ], $extra);
    }

    private function assertValidPeriod(string $start, string $end): void
    {
        $ds = \DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $de = \DateTimeImmutable::createFromFormat('Y-m-d', $end);
        if (!$ds || $ds->format('Y-m-d') !== $start || !$de || $de->format('Y-m-d') !== $end) {
            throw new \InvalidArgumentException('Invalid period dates; use Y-m-d.');
        }
        if ($ds > $de) {
            throw new \InvalidArgumentException('Period start must be on or before period end.');
        }
    }
}
