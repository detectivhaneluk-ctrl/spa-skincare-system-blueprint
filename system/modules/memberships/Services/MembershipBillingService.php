<?php

declare(strict_types=1);

namespace Modules\Memberships\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Memberships\Repositories\ClientMembershipRepository;
use Modules\Memberships\Repositories\MembershipBillingCycleRepository;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Memberships\Support\MembershipEntitlementSnapshot;
use Modules\Sales\Repositories\InvoiceRepository;
use Modules\Sales\Services\InvoiceService;

/**
 * Authoritative membership renewal billing: idempotent cycles + canonical {@see InvoiceService::create}.
 * Settlement is driven by {@see syncBillingCycleForInvoice} after canonical invoice/payment mutations (and cron/repair).
 * No payment gateway; dunning = cycle/row state + invoice balance checks.
 *
 * **Invoice-keyed repository reads** ({@see MembershipBillingCycleRepository::listByInvoiceId}, {@see MembershipBillingCycleRepository::listDistinctInvoiceIdsForReconcile},
 * {@see MembershipBillingCycleRepository::findForInvoice}, {@see MembershipBillingCycleRepository::findForUpdateForInvoice},
 * overdue/pending lists): invoice-plane org EXISTS on `invoices.branch_id` — strict under normal tenant context, OrUnscoped fallback for repair/cron without org.
 * Canonical invoice truth for settlement still flows through {@see InvoiceRepository::find} / {@see InvoiceService}.
 */
final class MembershipBillingService
{
    public function __construct(
        private Database $db,
        private ClientMembershipRepository $clientMemberships,
        private MembershipDefinitionRepository $definitions,
        private MembershipBillingCycleRepository $cycles,
        private InvoiceService $invoiceService,
        private InvoiceRepository $invoiceRepo,
        private AuditService $audit,
        private SettingsService $settings,
        private BranchContext $branchContext,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * After assign: schedule first renewal invoice window when definition.billing_enabled.
     * {@code branchContextId} must be a branch pin in the same organization as the membership (typically issuance branch).
     */
    public function initializeAfterAssign(int $clientMembershipId, int $branchContextId): void
    {
        $cm = $this->clientMemberships->findInTenantScope($clientMembershipId, $branchContextId);
        if (!$cm) {
            return;
        }
        if (($cm['status'] ?? '') !== 'active' || !empty($cm['cancel_at_period_end'])) {
            return;
        }
        $rawSnap = $cm['entitlement_snapshot_json'] ?? null;
        $snapJson = is_array($rawSnap) ? json_encode($rawSnap, JSON_THROW_ON_ERROR) : (is_string($rawSnap) ? $rawSnap : null);
        $snap = MembershipEntitlementSnapshot::decode($snapJson);
        if ($snap !== null) {
            if (empty($snap['billing_enabled'])) {
                return;
            }
            $dueDays = max(0, (int) ($snap['renewal_invoice_due_days'] ?? 14));
            $autoRenew = (int) !empty($snap['billing_auto_renew_enabled']);
        } else {
            $def = $this->definitions->findForClientMembershipContext((int) ($cm['membership_definition_id'] ?? 0), $clientMembershipId);
            if (!$def || !(int) ($def['billing_enabled'] ?? 0)) {
                return;
            }
            $dueDays = max(0, (int) ($def['renewal_invoice_due_days'] ?? 14));
            $autoRenew = (int) !empty($def['billing_auto_renew_enabled']);
        }
        $ends = new \DateTimeImmutable((string) $cm['ends_at']);
        $today = new \DateTimeImmutable('today');
        $billDate = $dueDays > 0
            ? $ends->sub(new \DateInterval('P' . $dueDays . 'D'))
            : $ends;
        if ($billDate < $today) {
            $billDate = $today;
        }
        $this->clientMemberships->updateInTenantScope($clientMembershipId, [
            'next_billing_at' => $billDate->format('Y-m-d'),
            'billing_state' => 'scheduled',
            'billing_auto_renew_enabled' => $autoRenew,
        ], $branchContextId);
    }

    /**
     * Process all memberships due for renewal invoicing (cron-safe, idempotent per period).
     *
     * Concurrency / re-runs: each membership is processed inside a DB transaction with
     * {@see ClientMembershipRepository::lockWithDefinitionForBilling} (row lock). Inserts respect
     * {@code UNIQUE uq_mbc_membership_period (client_membership_id, billing_period_start, billing_period_end)};
     * duplicate insert (1062) is treated as skip + advance scheduling.
     *
     * @return array{examined:int, invoiced:int, skipped:int, errors:list<string>}
     */
    public function processDueRenewalInvoices(?int $branchId = null): array
    {
        $ids = $this->cycles->listDueClientMembershipIds($branchId);
        $stats = ['examined' => count($ids), 'invoiced' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($ids as $row) {
            $cmId = (int) ($row['id'] ?? 0);
            $mbr = isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null
                ? (int) $row['branch_id'] : null;
            try {
                $r = $this->transactional(fn () => $this->processDueRenewalSingle($cmId, $mbr));
                if ($r === 'invoiced') {
                    ++$stats['invoiced'];
                } else {
                    ++$stats['skipped'];
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = 'cm#' . $cmId . ': ' . $e->getMessage();
                slog('error', 'membership.billing.renewal', $e->getMessage(), ['client_membership_id' => $cmId]);
            }
        }

        return $stats;
    }

    /**
     * Ordered cron pass: overdue flags → renewal invoices → extend terms on paid invoices.
     *
     * @return array{overdue: array{marked:int}, renewals: array{examined:int, invoiced:int, skipped:int, errors:list<string>}, applied: array{applied:int, skipped:int}}
     */
    public function runScheduledBillingPass(?int $branchId = null): array
    {
        return [
            'overdue' => $this->markOverdueCycles(),
            'renewals' => $this->processDueRenewalInvoices($branchId),
            'applied' => $this->applyPaidRenewalTerms(),
        ];
    }

    /**
     * Repair/backfill: resync cycles from current invoice rows (idempotent).
     * Maps canonical invoice/payment totals after {@see InvoiceService::recomputeInvoiceFinancials}.
     * Hooks: {@see PaymentService}, {@see InvoiceService} update/cancel/delete/gift-card redeem; cron/repair call this explicitly.
     *
     * @return array{invoices_synced:int}
     */
    public function reconcileBillingCyclesFromCanonicalInvoices(?int $invoiceId = null, ?int $clientMembershipId = null, ?int $branchId = null): array
    {
        $ids = $this->cycles->listDistinctInvoiceIdsForReconcile($invoiceId, $clientMembershipId, $branchId);
        foreach ($ids as $iid) {
            $this->syncBillingCycleForInvoice($iid);
        }

        return ['invoices_synced' => count($ids)];
    }

    /**
     * Operator-only: re-run {@see syncBillingCycleForInvoice} for a cycle that still appears in the refund-review inbox.
     * Membership term dates are not rolled back here; canonical invoice/payment drives the outcome.
     *
     * @return array{cycle_snapshot_before: array{status: string, renewal_applied_at: ?string}, cycle_snapshot_after: array{status: string, renewal_applied_at: ?string}, invoice_id: int}
     */
    public function operatorReconcileBillingCycleRefundReview(int $cycleId): array
    {
        $cycle = $this->cycles->find($cycleId);
        if (!$cycle) {
            throw new \DomainException('Membership billing cycle not found.');
        }
        $invoiceId = (int) ($cycle['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            throw new \DomainException('Billing cycle has no invoice.');
        }
        $inv = $this->invoiceRepo->find($invoiceId);
        if (!$inv) {
            throw new \DomainException('Invoice not found.');
        }
        if (!$this->qualifiesRefundReviewInbox($cycle, $inv)) {
            throw new \DomainException('This renewal billing cycle is not in the refund-review inbox for the current canonical invoice state.');
        }
        $cmId = (int) ($cycle['client_membership_id'] ?? 0);
        $invBranchOp = (int) ($inv['branch_id'] ?? 0);
        $branchId = $this->branchIdForClientMembership($cmId, $invBranchOp > 0 ? $invBranchOp : null);
        $before = $this->cycleSnapshot($cycle);
        $actor = $this->currentUserId();
        $this->syncBillingCycleForInvoice($invoiceId);
        $afterCycle = $this->cycles->findForInvoice($cycleId, $invoiceId) ?? $cycle;
        $after = $this->cycleSnapshot($afterCycle);
        $this->audit->log('membership_billing_cycle_refund_review_operator_reconcile', 'membership_billing_cycle', $cycleId, $actor, $branchId, [
            'client_membership_id' => $cmId,
            'invoice_id' => $invoiceId,
            'cycle_snapshot_before' => $before,
            'cycle_snapshot_after' => $after,
        ]);

        return [
            'cycle_snapshot_before' => $before,
            'cycle_snapshot_after' => $after,
            'invoice_id' => $invoiceId,
        ];
    }

    /**
     * Operator-only: records manual review without changing rows (membership/invoice truth unchanged).
     */
    public function operatorAcknowledgeBillingCycleRefundReview(int $cycleId, ?string $note): void
    {
        $cycle = $this->cycles->find($cycleId);
        if (!$cycle) {
            throw new \DomainException('Membership billing cycle not found.');
        }
        $invoiceId = (int) ($cycle['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            throw new \DomainException('Billing cycle has no invoice.');
        }
        $inv = $this->invoiceRepo->find($invoiceId);
        if (!$inv) {
            throw new \DomainException('Invoice not found.');
        }
        if (!$this->qualifiesRefundReviewInbox($cycle, $inv)) {
            throw new \DomainException('This renewal billing cycle is not in the refund-review inbox for the current canonical invoice state.');
        }
        $cmId = (int) ($cycle['client_membership_id'] ?? 0);
        $invBranchAck = (int) ($inv['branch_id'] ?? 0);
        $branchId = $this->branchIdForClientMembership($cmId, $invBranchAck > 0 ? $invBranchAck : null);
        $noteTrim = $this->truncateOperatorNote($note);
        $this->audit->log('membership_billing_cycle_refund_review_acknowledged', 'membership_billing_cycle', $cycleId, $this->currentUserId(), $branchId, [
            'client_membership_id' => $cmId,
            'invoice_id' => $invoiceId,
            'note' => $noteTrim,
        ]);
    }

    public function syncBillingCycleForInvoice(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        $cycleRows = $this->cycles->listByInvoiceId($invoiceId);
        if ($cycleRows === []) {
            return;
        }

        $invAny = $this->invoiceRepo->find($invoiceId, true);
        if (!$invAny) {
            return;
        }

        if (!empty($invAny['deleted_at'])) {
            foreach ($cycleRows as $row) {
                $this->transactional(function () use ($row, $invAny): void {
                    $this->settleCycleForDeletedInvoice((int) $row['id'], $invAny);
                });
            }

            return;
        }

        $this->invoiceService->recomputeInvoiceFinancials($invoiceId);
        $inv = $this->invoiceRepo->find($invoiceId);
        if (!$inv) {
            return;
        }

        foreach ($cycleRows as $row) {
            $this->transactional(function () use ($row, $inv): void {
                $this->settleSingleCycleLocked((int) $row['id'], $inv);
            });
        }
    }

    /**
     * Overdue sweep: resync candidate invoices (past due, balance due). Uses the same settlement core as payments.
     *
     * @return array{marked:int}
     */
    public function markOverdueCycles(): array
    {
        $invoiceIds = $this->cycles->listDistinctInvoiceIdsOverdueCandidates();
        $marked = 0;
        foreach ($invoiceIds as $iid) {
            $before = [];
            foreach ($this->cycles->listByInvoiceId($iid) as $c) {
                $before[(int) $c['id']] = (string) ($c['status'] ?? '');
            }
            $this->syncBillingCycleForInvoice($iid);
            foreach ($this->cycles->listByInvoiceId($iid) as $c) {
                $id = (int) $c['id'];
                $prev = $before[$id] ?? null;
                if (($c['status'] ?? '') === 'overdue' && $prev !== 'overdue') {
                    ++$marked;
                }
            }
        }

        return ['marked' => $marked];
    }

    /**
     * Batch backstop: distinct renewal invoices that still need term application (same as payment-time settlement).
     *
     * @return array{applied:int, skipped:int}
     */
    public function applyPaidRenewalTerms(): array
    {
        $invoiceIds = $this->cycles->listDistinctInvoiceIdsPendingRenewalApplication();
        $applied = 0;
        foreach ($invoiceIds as $iid) {
            $hadPending = false;
            foreach ($this->cycles->listByInvoiceId($iid) as $c) {
                if ($c['renewal_applied_at'] === null || $c['renewal_applied_at'] === '') {
                    $hadPending = true;
                    break;
                }
            }
            $this->syncBillingCycleForInvoice($iid);
            $stillPending = false;
            foreach ($this->cycles->listByInvoiceId($iid) as $c) {
                if ($c['renewal_applied_at'] === null || $c['renewal_applied_at'] === '') {
                    $stillPending = true;
                    break;
                }
            }
            if ($hadPending && !$stillPending) {
                ++$applied;
            }
        }

        $skipped = count($invoiceIds) - $applied;

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $invTrashed row from {@see InvoiceRepository::find} with trashed
     */
    private function settleCycleForDeletedInvoice(int $cycleId, array $invTrashed): void
    {
        $invIdTrashed = (int) ($invTrashed['id'] ?? 0);
        $invBranchTrashed = (int) ($invTrashed['branch_id'] ?? 0);
        $cycle = $this->cycles->findForUpdateForInvoice($cycleId, $invIdTrashed);
        if (!$cycle) {
            return;
        }
        $before = $this->cycleSnapshot($cycle);
        $cmId = (int) ($cycle['client_membership_id'] ?? 0);
        $primary = $this->isPrimaryInvoicedCycle($cmId, $cycleId);

        if (($cycle['status'] ?? '') !== 'void') {
            $this->cycles->update($cycleId, ['status' => 'void']);
            $this->patchPrimaryMembershipAfterVoid($cmId, $primary, $invBranchTrashed > 0 ? $invBranchTrashed : null);
            $branchId = $this->branchIdForClientMembership($cmId, $invBranchTrashed > 0 ? $invBranchTrashed : null);
            $this->audit->log('membership_billing_cycle_voided', 'membership_billing_cycle', $cycleId, null, $branchId, [
                'client_membership_id' => $cmId,
                'invoice_id' => $invIdTrashed,
                'reason' => 'invoice_deleted',
            ]);
        }
        $cycle = $this->cycles->findForInvoice($cycleId, $invIdTrashed) ?? $cycle;
        $this->maybeLogSettlementSynced($before, $cycle, $cmId, $invIdTrashed, $invBranchTrashed > 0 ? $invBranchTrashed : null);
    }

    /**
     * @param array<string, mixed> $inv Active invoice (not soft-deleted)
     */
    private function settleSingleCycleLocked(int $cycleId, array $inv): void
    {
        $invoiceId = (int) ($inv['id'] ?? 0);
        $invBranch = (int) ($inv['branch_id'] ?? 0);
        $cycle = $this->cycles->findForUpdateForInvoice($cycleId, $invoiceId);
        if (!$cycle) {
            return;
        }
        $before = $this->cycleSnapshot($cycle);
        $cmId = (int) ($cycle['client_membership_id'] ?? 0);
        $primary = $this->isPrimaryInvoicedCycle($cmId, $cycleId);
        $branchId = $this->branchIdForClientMembership($cmId, $invBranch > 0 ? $invBranch : null);

        $status = (string) ($inv['status'] ?? '');
        $total = round((float) ($inv['total_amount'] ?? 0), 2);
        $paid = round((float) ($inv['paid_amount'] ?? 0), 2);
        $dueAt = trim((string) ($cycle['due_at'] ?? ''));
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $renewalApplied = $cycle['renewal_applied_at'] !== null && $cycle['renewal_applied_at'] !== '';

        if ($status === 'cancelled') {
            if (($cycle['status'] ?? '') !== 'void') {
                $this->cycles->update($cycleId, ['status' => 'void']);
                $this->patchPrimaryMembershipAfterVoid($cmId, $primary, $invBranch > 0 ? $invBranch : null);
                $this->audit->log('membership_billing_cycle_voided', 'membership_billing_cycle', $cycleId, null, $branchId, [
                    'client_membership_id' => $cmId,
                    'invoice_id' => $invoiceId,
                    'reason' => 'invoice_cancelled',
                ]);
            }
            $cycle = $this->cycles->findForInvoice($cycleId, $invoiceId) ?? $cycle;
            $this->maybeLogSettlementSynced($before, $cycle, $cmId, $invoiceId, $invBranch > 0 ? $invBranch : null);

            return;
        }

        if ($status === 'refunded') {
            if ($renewalApplied) {
                $this->applyRefundReviewState($cycle, $cycleId, $cmId, $primary, $invoiceId, $branchId, (string) $before['status'], $invBranch > 0 ? $invBranch : null);
            } else {
                if (($cycle['status'] ?? '') !== 'void') {
                    $this->cycles->update($cycleId, ['status' => 'void']);
                    $this->patchPrimaryMembershipAfterVoid($cmId, $primary, $invBranch > 0 ? $invBranch : null);
                    $this->audit->log('membership_billing_cycle_voided', 'membership_billing_cycle', $cycleId, null, $branchId, [
                        'client_membership_id' => $cmId,
                        'invoice_id' => $invoiceId,
                        'reason' => 'invoice_refunded',
                    ]);
                }
            }
            $cycle = $this->cycles->findForInvoice($cycleId, $invoiceId) ?? $cycle;
            $this->maybeLogSettlementSynced($before, $cycle, $cmId, $invoiceId, $invBranch > 0 ? $invBranch : null);

            return;
        }

        if ($total <= 0) {
            if (($cycle['status'] ?? '') !== 'void') {
                $this->cycles->update($cycleId, ['status' => 'void']);
                $this->patchPrimaryMembershipAfterVoid($cmId, $primary, $invBranch > 0 ? $invBranch : null);
                $this->audit->log('membership_billing_cycle_voided', 'membership_billing_cycle', $cycleId, null, $branchId, [
                    'client_membership_id' => $cmId,
                    'invoice_id' => $invoiceId,
                    'reason' => 'invoice_non_positive_total',
                ]);
            }
            $cycle = $this->cycles->findForInvoice($cycleId, $invoiceId) ?? $cycle;
            $this->maybeLogSettlementSynced($before, $cycle, $cmId, $invoiceId, $invBranch > 0 ? $invBranch : null);

            return;
        }

        $fullyPaid = $paid >= $total;

        if ($fullyPaid) {
            $this->settleFullyPaidRenewal($cycle, $cycleId, $cmId, $primary, $invoiceId, $branchId, $renewalApplied, (string) $before['status'], $invBranch > 0 ? $invBranch : null);
            $cycle = $this->cycles->findForInvoice($cycleId, $invoiceId) ?? $cycle;
            $this->maybeLogSettlementSynced($before, $cycle, $cmId, $invoiceId, $invBranch > 0 ? $invBranch : null);

            return;
        }

        if ($renewalApplied) {
            $this->applyRefundReviewState($cycle, $cycleId, $cmId, $primary, $invoiceId, $branchId, (string) $before['status'], $invBranch > 0 ? $invBranch : null);
            $cycle = $this->cycles->findForInvoice($cycleId, $invoiceId) ?? $cycle;
            $this->maybeLogSettlementSynced($before, $cycle, $cmId, $invoiceId, $invBranch > 0 ? $invBranch : null);

            return;
        }

        $pastDue = $dueAt !== '' && $dueAt < $today;
        $newStatus = $pastDue ? 'overdue' : 'invoiced';
        if (($cycle['status'] ?? '') !== $newStatus) {
            $this->cycles->update($cycleId, ['status' => $newStatus]);
        }
        $cm = $this->clientMembershipReadForSettlement($cmId, $invBranch > 0 ? $invBranch : null);
        if ($primary && $cm && ($cm['status'] ?? '') === 'active') {
            $targetBilling = $pastDue ? 'overdue' : 'invoiced';
            if (($cm['billing_state'] ?? '') !== $targetBilling) {
                $this->applyClientMembershipColumnPatch($cmId, ['billing_state' => $targetBilling], $invBranch > 0 ? $invBranch : null, $cm);
            }
        }
        if ($newStatus === 'overdue' && (string) $before['status'] !== 'overdue') {
            $this->audit->log('membership_billing_cycle_overdue', 'membership_billing_cycle', $cycleId, null, $branchId, [
                'client_membership_id' => $cmId,
                'invoice_id' => $invoiceId,
            ]);
        }
        $cycle = $this->cycles->findForInvoice($cycleId, $invoiceId) ?? $cycle;
        $this->maybeLogSettlementSynced($before, $cycle, $cmId, $invoiceId, $invBranch > 0 ? $invBranch : null);
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function settleFullyPaidRenewal(
        array $cycle,
        int $cycleId,
        int $cmId,
        bool $primary,
        int $invoiceId,
        ?int $branchId,
        bool $renewalAlreadyApplied,
        string $previousCycleStatus,
        ?int $invoiceBranchId = null
    ): void {
        if ($renewalAlreadyApplied) {
            if (($cycle['status'] ?? '') !== 'paid') {
                $this->cycles->update($cycleId, ['status' => 'paid']);
            }
            if ($previousCycleStatus !== 'paid') {
                $this->audit->log('membership_billing_cycle_paid', 'membership_billing_cycle', $cycleId, null, $branchId, [
                    'client_membership_id' => $cmId,
                    'invoice_id' => $invoiceId,
                    'renewal_applied_at_already_set' => true,
                ]);
            }

            return;
        }

        $cm = $this->clientMembershipReadForSettlement($cmId, $invoiceBranchId);
        if (!$cm) {
            return;
        }
        $def = $this->definitions->findForClientMembershipContext((int) ($cm['membership_definition_id'] ?? 0), $cmId);
        if (!$def || (int) ($def['billing_enabled'] ?? 0) !== 1) {
            $this->cycles->update($cycleId, [
                'status' => 'paid',
                'renewal_applied_at' => date('Y-m-d H:i:s'),
            ]);
            $this->audit->log('membership_billing_cycle_paid', 'membership_billing_cycle', $cycleId, null, $branchId, [
                'client_membership_id' => $cmId,
                'invoice_id' => $invoiceId,
                'term_extension_skipped_reason' => 'billing_disabled_or_missing_definition',
            ]);

            return;
        }

        $periodEnd = (string) ($cycle['billing_period_end'] ?? '');
        if ($periodEnd === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) !== 1) {
            return;
        }
        $defShape = $this->definitionShapeFromRow($def);
        $nextBill = $this->computeNextBillingDateAfterPeriodEnd(new \DateTimeImmutable($periodEnd), $defShape);

        if (($cm['status'] ?? '') === 'active' && empty($cm['cancel_at_period_end'])) {
            $this->applyClientMembershipColumnPatch($cmId, [
                'ends_at' => $periodEnd,
                'billing_state' => 'scheduled',
                'next_billing_at' => $nextBill,
            ], $invoiceBranchId !== null && $invoiceBranchId > 0 ? $invoiceBranchId : null, $cm);
        } else {
            $skipReason = !empty($cm['cancel_at_period_end'])
                ? 'cancellation_scheduled_at_period_end'
                : 'client_membership_not_active';
            $this->cycles->update($cycleId, [
                'status' => 'paid',
                'renewal_applied_at' => date('Y-m-d H:i:s'),
            ]);
            $this->audit->log('membership_billing_cycle_paid', 'membership_billing_cycle', $cycleId, null, $branchId, [
                'client_membership_id' => $cmId,
                'invoice_id' => $invoiceId,
                'term_extension_skipped_reason' => $skipReason,
            ]);

            return;
        }

        $this->cycles->update($cycleId, [
            'status' => 'paid',
            'renewal_applied_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit->log('membership_renewal_term_extended', 'client_membership', $cmId, null, $branchId, [
            'membership_billing_cycle_id' => $cycleId,
            'invoice_id' => $invoiceId,
            'new_ends_at' => $periodEnd,
            'next_billing_at' => $nextBill,
        ]);
        $this->audit->log('membership_billing_cycle_paid', 'membership_billing_cycle', $cycleId, null, $branchId, [
            'client_membership_id' => $cmId,
            'invoice_id' => $invoiceId,
        ]);
    }

    /**
     * @param array<string, mixed> $cycle row before update (locked)
     */
    private function applyRefundReviewState(
        array $cycle,
        int $cycleId,
        int $cmId,
        bool $primary,
        int $invoiceId,
        ?int $branchId,
        string $previousCycleStatus,
        ?int $invoiceBranchId = null
    ): void {
        if (($cycle['status'] ?? '') !== 'invoiced') {
            $this->cycles->update($cycleId, ['status' => 'invoiced']);
        }
        $cm = $this->clientMembershipReadForSettlement($cmId, $invoiceBranchId);
        if ($primary && $cm && ($cm['status'] ?? '') === 'active') {
            if (($cm['billing_state'] ?? '') !== 'invoiced') {
                $this->applyClientMembershipColumnPatch($cmId, ['billing_state' => 'invoiced'], $invoiceBranchId, $cm);
            }
        }
        if ($previousCycleStatus === 'paid') {
            $this->audit->log('membership_billing_cycle_refund_review_required', 'membership_billing_cycle', $cycleId, null, $branchId, [
                'client_membership_id' => $cmId,
                'invoice_id' => $invoiceId,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $cycle
     * @param array<string, mixed> $inv active invoice (not soft-deleted)
     */
    public function qualifiesRefundReviewInbox(array $cycle, array $inv): bool
    {
        if (($cycle['renewal_applied_at'] ?? null) === null || $cycle['renewal_applied_at'] === '') {
            return false;
        }
        if (($cycle['status'] ?? '') !== 'invoiced') {
            return false;
        }
        if ((int) ($cycle['invoice_id'] ?? 0) <= 0) {
            return false;
        }
        $total = round((float) ($inv['total_amount'] ?? 0), 2);
        $paid = round((float) ($inv['paid_amount'] ?? 0), 2);
        $status = (string) ($inv['status'] ?? '');

        return $status === 'refunded'
            || ($paid < $total && $total > 0);
    }

    private function truncateOperatorNote(?string $note): ?string
    {
        $n = $note !== null ? trim($note) : '';
        if ($n === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($n, 0, 2000, 'UTF-8');
        }

        return strlen($n) <= 2000 ? $n : substr($n, 0, 2000);
    }

    private function currentUserId(): ?int
    {
        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();

        return isset($user['id']) ? (int) $user['id'] : null;
    }

    private function patchPrimaryMembershipAfterVoid(int $cmId, bool $primary, ?int $invoiceBranchId = null): void
    {
        if (!$primary || $cmId <= 0) {
            return;
        }
        $cm = $this->clientMembershipReadForSettlement($cmId, $invoiceBranchId);
        if (!$cm || ($cm['status'] ?? '') !== 'active') {
            return;
        }
        $def = $this->definitions->findForClientMembershipContext((int) ($cm['membership_definition_id'] ?? 0), $cmId);
        if ($def && (int) ($def['billing_enabled'] ?? 0) && (int) ($cm['billing_auto_renew_enabled'] ?? 0)) {
            if (($cm['billing_state'] ?? '') !== 'scheduled') {
                $this->applyClientMembershipColumnPatch($cmId, ['billing_state' => 'scheduled'], $invoiceBranchId, $cm);
            }
        } elseif (($cm['billing_state'] ?? '') !== 'inactive') {
            $this->applyClientMembershipColumnPatch($cmId, ['billing_state' => 'inactive'], $invoiceBranchId, $cm);
        }
    }

    private function isPrimaryInvoicedCycle(int $clientMembershipId, int $cycleId): bool
    {
        $maxId = $this->cycles->maxInvoicedCycleIdForMembership($clientMembershipId);

        return $maxId !== null && $maxId === $cycleId;
    }

    private function branchIdForClientMembership(int $cmId, ?int $invoiceBranchHint = null): ?int
    {
        if ($invoiceBranchHint !== null && $invoiceBranchHint > 0) {
            return $invoiceBranchHint;
        }
        $cm = $this->clientMembershipReadForSettlement($cmId, null);
        if (!$cm || !isset($cm['branch_id']) || $cm['branch_id'] === '' || $cm['branch_id'] === null) {
            $ctx = $this->branchContext->getCurrentBranchId();

            return $ctx !== null && $ctx > 0 ? $ctx : null;
        }

        return (int) $cm['branch_id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function clientMembershipReadForSettlement(int $cmId, ?int $invoiceBranchId): ?array
    {
        if ($invoiceBranchId !== null && $invoiceBranchId > 0) {
            $scoped = $this->clientMemberships->findInTenantScope($cmId, $invoiceBranchId);
            if ($scoped !== null) {
                return $scoped;
            }
        }
        $ctx = $this->branchContext->getCurrentBranchId();
        if ($ctx !== null && $ctx > 0) {
            $scoped = $this->clientMemberships->findInTenantScope($cmId, $ctx);
            if ($scoped !== null) {
                return $scoped;
            }
        }

        return $this->clientMemberships->find($cmId);
    }

    /**
     * @param array<string, mixed> $patch column => value (normalized keys only)
     */
    private function applyClientMembershipColumnPatch(int $cmId, array $patch, ?int $invoiceBranchId, ?array $cmRow): void
    {
        $pin = $this->resolveUpdatePinForCmPatch($invoiceBranchId, $cmRow);
        $this->applyClientMembershipPatchWithOptionalPin($cmId, $patch, $pin);
    }

    /** @param array<string, mixed> $patch */
    private function applyClientMembershipPatchWithOptionalPin(int $cmId, array $patch, ?int $branchContextPin): void
    {
        if ($branchContextPin !== null && $branchContextPin > 0) {
            $this->clientMemberships->updateInTenantScope($cmId, $patch, $branchContextPin);

            return;
        }
        $this->clientMemberships->updateRepairOrUnscopedById($cmId, $patch);
    }

    private function resolveUpdatePinForCmPatch(?int $invoiceBranchId, ?array $cmRow): ?int
    {
        if ($invoiceBranchId !== null && $invoiceBranchId > 0) {
            return $invoiceBranchId;
        }
        if ($cmRow !== null && isset($cmRow['branch_id']) && (int) $cmRow['branch_id'] > 0) {
            return (int) $cmRow['branch_id'];
        }
        $ctx = $this->branchContext->getCurrentBranchId();
        if ($ctx !== null && $ctx > 0) {
            return $ctx;
        }

        return $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
    }

    /**
     * @param array<string, mixed> $cycle
     * @return array{status: string, renewal_applied_at: ?string}
     */
    private function cycleSnapshot(array $cycle): array
    {
        $rap = $cycle['renewal_applied_at'] ?? null;

        return [
            'status' => (string) ($cycle['status'] ?? ''),
            'renewal_applied_at' => $rap !== null && $rap !== '' ? (string) $rap : null,
        ];
    }

    /**
     * @param array{status: string, renewal_applied_at: ?string} $before
     * @param array<string, mixed> $afterCycle
     */
    private function maybeLogSettlementSynced(array $before, array $afterCycle, int $cmId, int $invoiceId, ?int $invoiceBranchHint = null): void
    {
        $after = $this->cycleSnapshot($afterCycle);
        if ($before === $after) {
            return;
        }
        $branchId = $this->branchIdForClientMembership($cmId, $invoiceBranchHint);
        $this->audit->log('membership_billing_cycle_settlement_synced', 'membership_billing_cycle', (int) ($afterCycle['id'] ?? 0), null, $branchId, [
            'client_membership_id' => $cmId,
            'invoice_id' => $invoiceId,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * @return 'invoiced'|'skipped'
     */
    private function processDueRenewalSingle(int $clientMembershipId, ?int $membershipBranchId = null): string
    {
        $row = $this->clientMemberships->lockWithDefinitionForBilling($clientMembershipId, $membershipBranchId);
        if (!$row) {
            return 'skipped';
        }
        if (!(int) ($row['def_billing_enabled'] ?? 0)
            || ($row['def_deleted_at'] ?? null) !== null
            || ($row['def_status'] ?? '') !== 'active'
            || ($row['status'] ?? '') !== 'active'
            || !(int) ($row['billing_auto_renew_enabled'] ?? 0)
            || !empty($row['cancel_at_period_end'])
        ) {
            return 'skipped';
        }
        $nb = trim((string) ($row['next_billing_at'] ?? ''));
        if ($nb === '' || $nb > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            return 'skipped';
        }
        $endsAtStr = trim((string) ($row['ends_at'] ?? ''));
        if ($endsAtStr === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsAtStr) !== 1) {
            return 'skipped';
        }
        $defShape = [
            'duration_days' => (int) ($row['def_duration_days'] ?? 1),
            'billing_interval_unit' => $row['def_billing_interval_unit'] ?? null,
            'billing_interval_count' => isset($row['def_billing_interval_count']) ? (int) $row['def_billing_interval_count'] : null,
            'renewal_invoice_due_days' => max(0, (int) ($row['def_renewal_invoice_due_days'] ?? 14)),
        ];
        $endsAt = new \DateTimeImmutable($endsAtStr);
        $periodStart = $endsAt->add(new \DateInterval('P1D'));
        $periodEnd = $this->computeBillingPeriodEnd($periodStart, $defShape);
        $ps = $periodStart->format('Y-m-d');
        $pe = $periodEnd->format('Y-m-d');
        $cycleLookupBranch = ($membershipBranchId !== null && $membershipBranchId > 0)
            ? $membershipBranchId
            : (isset($row['branch_id']) && (int) $row['branch_id'] > 0 ? (int) $row['branch_id'] : null);
        if ($cycleLookupBranch === null || $cycleLookupBranch <= 0) {
            $bctx = $this->branchContext->getCurrentBranchId();
            $cycleLookupBranch = ($bctx !== null && $bctx > 0) ? $bctx : null;
        }
        if ($this->cycles->findByMembershipAndPeriod($clientMembershipId, $ps, $pe, $cycleLookupBranch)) {
            $this->advanceNextBillingOnly($clientMembershipId, $pe, $defShape, $cycleLookupBranch);
            return 'skipped';
        }
        $renewalPrice = $row['def_renewal_price'] ?? null;
        $basePrice = $row['def_price'] ?? null;
        $amount = $renewalPrice !== null && $renewalPrice !== '' ? (float) $renewalPrice : ($basePrice !== null && $basePrice !== '' ? (float) $basePrice : 0.0);
        if (!is_finite($amount) || $amount <= 0) {
            $this->audit->log('membership_renewal_billing_skipped', 'client_membership', $clientMembershipId, null, $this->branchIdFromRow($row), [
                'reason' => 'invalid_or_missing_renewal_price',
            ]);
            return 'skipped';
        }
        $issueDate = new \DateTimeImmutable('today');
        $dueDays = max(0, (int) ($defShape['renewal_invoice_due_days'] ?? 0));
        $dueAt = $dueDays > 0
            ? $issueDate->add(new \DateInterval('P' . $dueDays . 'D'))
            : $issueDate;
        $cycleId = 0;
        try {
            $cycleId = $this->cycles->insert([
                'client_membership_id' => $clientMembershipId,
                'billing_period_start' => $ps,
                'billing_period_end' => $pe,
                'due_at' => $dueAt->format('Y-m-d'),
                'status' => 'pending',
                'attempt_count' => 1,
            ]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $this->advanceNextBillingOnly($clientMembershipId, $pe, $defShape, $cycleLookupBranch);
                return 'skipped';
            }
            throw $e;
        }
        $defName = trim((string) ($row['def_name'] ?? 'Membership'));
        $desc = sprintf(
            'Membership renewal: %s (%s – %s) [client_membership_id=%d]',
            $defName,
            $ps,
            $pe,
            $clientMembershipId
        );
        $notes = 'membership_renewal:client_membership_id=' . $clientMembershipId . ';billing_cycle_id=' . $cycleId;
        $branchId = $this->branchIdFromRow($row);
        $termsBlock = $this->settings->membershipTermsDocumentBlock($branchId);
        if ($termsBlock !== null) {
            $notes .= "\n\n" . $termsBlock;
        }
        $invoiceId = $this->invoiceService->create([
            'branch_id' => $branchId,
            'client_id' => (int) ($row['client_id'] ?? 0),
            'appointment_id' => null,
            'status' => 'open',
            'notes' => $notes,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'items' => [
                [
                    'item_type' => 'manual',
                    'source_id' => null,
                    'description' => $desc,
                    'quantity' => 1,
                    'unit_price' => round($amount, 2),
                    'discount_amount' => 0,
                    'tax_rate' => 0,
                ],
            ],
        ]);
        $this->cycles->update($cycleId, [
            'invoice_id' => $invoiceId,
            'status' => 'invoiced',
        ]);
        $nextBill = $this->computeNextBillingDateAfterPeriodEnd($periodEnd, $defShape);
        $this->applyClientMembershipPatchWithOptionalPin($clientMembershipId, [
            'last_billed_at' => $issueDate->format('Y-m-d'),
            'billing_state' => 'invoiced',
            'next_billing_at' => $nextBill,
        ], $cycleLookupBranch);
        $this->audit->log('membership_renewal_invoiced', 'membership_billing_cycle', $cycleId, null, $branchId, [
            'client_membership_id' => $clientMembershipId,
            'invoice_id' => $invoiceId,
            'billing_period_start' => $ps,
            'billing_period_end' => $pe,
        ]);

        return 'invoiced';
    }

    private function advanceNextBillingOnly(int $clientMembershipId, string $periodEndYmd, array $defShape, ?int $branchContextPin): void
    {
        $periodEnd = new \DateTimeImmutable($periodEndYmd);
        $nextBill = $this->computeNextBillingDateAfterPeriodEnd($periodEnd, $defShape);
        $this->applyClientMembershipPatchWithOptionalPin($clientMembershipId, [
            'next_billing_at' => $nextBill,
        ], $branchContextPin);
    }

    /**
     * @param array{duration_days:int, billing_interval_unit:?string, billing_interval_count:?int, renewal_invoice_due_days:int} $defShape
     */
    private function computeNextBillingDateAfterPeriodEnd(\DateTimeImmutable $periodEnd, array $defShape): string
    {
        $p2Start = $periodEnd->add(new \DateInterval('P1D'));
        $p2End = $this->computeBillingPeriodEnd($p2Start, $defShape);
        $dueDays = max(0, (int) ($defShape['renewal_invoice_due_days'] ?? 0));
        $nb = $dueDays > 0
            ? $p2End->sub(new \DateInterval('P' . $dueDays . 'D'))
            : $p2End;
        if ($nb < $p2Start) {
            $nb = $p2Start;
        }
        return $nb->format('Y-m-d');
    }

    /**
     * @param array{duration_days:int, billing_interval_unit:?string, billing_interval_count:?int, renewal_invoice_due_days:int} $defShape
     */
    private function computeBillingPeriodEnd(\DateTimeImmutable $periodStart, array $defShape): \DateTimeImmutable
    {
        $unit = isset($defShape['billing_interval_unit']) && $defShape['billing_interval_unit'] !== ''
            ? (string) $defShape['billing_interval_unit']
            : '';
        $count = isset($defShape['billing_interval_count']) ? (int) $defShape['billing_interval_count'] : 0;
        if ($unit !== '' && $count > 0) {
            return match ($unit) {
                'day' => $periodStart->add(new \DateInterval('P' . $count . 'D')),
                'week' => $periodStart->add(new \DateInterval('P' . ($count * 7) . 'D')),
                'month' => $periodStart->modify('+' . $count . ' months'),
                'year' => $periodStart->modify('+' . $count . ' years'),
                default => $this->defaultPeriodEndFromDuration($periodStart, $defShape),
            };
        }

        return $this->defaultPeriodEndFromDuration($periodStart, $defShape);
    }

    /**
     * @param array{duration_days:int} $defShape
     */
    private function defaultPeriodEndFromDuration(\DateTimeImmutable $periodStart, array $defShape): \DateTimeImmutable
    {
        $dur = max(1, (int) ($defShape['duration_days'] ?? 1));
        return $periodStart->add(new \DateInterval('P' . $dur . 'D'));
    }

    /**
     * @param array<string, mixed> $def
     * @return array{duration_days:int, billing_interval_unit:?string, billing_interval_count:?int, renewal_invoice_due_days:int}
     */
    private function definitionShapeFromRow(array $def): array
    {
        return [
            'duration_days' => max(1, (int) ($def['duration_days'] ?? 1)),
            'billing_interval_unit' => isset($def['billing_interval_unit']) && $def['billing_interval_unit'] !== ''
                ? (string) $def['billing_interval_unit']
                : null,
            'billing_interval_count' => isset($def['billing_interval_count']) ? (int) $def['billing_interval_count'] : null,
            'renewal_invoice_due_days' => max(0, (int) ($def['renewal_invoice_due_days'] ?? 14)),
        ];
    }

    /** @param array<string, mixed> $row */
    private function branchIdFromRow(array $row): ?int
    {
        if (!isset($row['branch_id']) || $row['branch_id'] === '' || $row['branch_id'] === null) {
            return null;
        }

        return (int) $row['branch_id'];
    }

    private function transactional(callable $fn): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $fn();
            if ($started) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
