<?php

declare(strict_types=1);

namespace Modules\PublicCommerce\Services;

use Core\Audit\AuditService;
use Core\Contracts\PublicCommerceFulfillmentReconciler as PublicCommerceFulfillmentReconcilerContract;
use Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository;

/**
 * Trusted internal repair for public-commerce rows that never received a successful reconciler pass
 * (legacy data, missed hooks, or prerequisites that later became satisfiable). Never posts payments.
 */
final class PublicCommerceFulfillmentRepairService
{
    public function __construct(
        private PublicCommercePurchaseRepository $purchases,
        private PublicCommerceFulfillmentReconcilerContract $reconciler,
        private PublicCommerceFulfillmentReconcileRecoveryService $fulfillmentReconcileRecovery,
        private AuditService $audit,
    ) {
    }

    /**
     * Read-only snapshot for operators (CLI --dry-run).
     *
     * @return list<array<string, mixed>>
     */
    public function listRepairCandidates(?int $branchId, ?int $invoiceId, int $limit = 100): array
    {
        return $this->purchases->listPurchasesForFulfillmentRepair($branchId, $invoiceId, $limit);
    }

    /**
     * Re-run {@see PublicCommerceFulfillmentReconciler::reconcile} per distinct valid invoice (idempotent).
     *
     * @return array{
     *   examined_purchases: int,
     *   invoices_reconciled: int,
     *   broken_reference: int,
     *   duplicate_invoice_skipped: int,
     *   reconcile_applied: int,
     *   reconcile_still_pending: int,
     *   reconcile_skipped: int,
     *   reconcile_blocked: int,
     *   reconcile_noop_or_error: int
     * }
     */
    public function repairBatch(?int $branchId, ?int $invoiceId, int $limit = 100): array
    {
        $rows = $this->purchases->listPurchasesForFulfillmentRepair($branchId, $invoiceId, $limit);
        $stats = [
            'examined_purchases' => count($rows),
            'invoices_reconciled' => 0,
            'broken_reference' => 0,
            'duplicate_invoice_skipped' => 0,
            'reconcile_applied' => 0,
            'reconcile_still_pending' => 0,
            'reconcile_skipped' => 0,
            'reconcile_blocked' => 0,
            'reconcile_noop_or_error' => 0,
        ];

        $this->audit->log('public_commerce_fulfillment_repair_batch_started', 'public_commerce_purchase', null, null, $branchId, [
            'invoice_filter' => $invoiceId,
            'limit' => $limit,
            'candidates' => $stats['examined_purchases'],
        ]);

        $seenInvoiceIds = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['id'] ?? 0);
            $iid = (int) ($row['invoice_id'] ?? 0);
            $refState = (string) ($row['repair_reference_state'] ?? '');
            $branch = (int) ($row['branch_id'] ?? 0) > 0 ? (int) ($row['branch_id'] ?? 0) : null;

            $this->audit->log('public_commerce_fulfillment_repair_requested', 'public_commerce_purchase', $pid > 0 ? $pid : null, null, $branch, [
                'purchase_id' => $pid,
                'invoice_id' => $iid,
                'purchase_status' => (string) ($row['status'] ?? ''),
                'repair_reference_state' => $refState,
            ]);

            if ($refState !== 'ok') {
                $this->audit->log('public_commerce_fulfillment_repair_broken_reference', 'public_commerce_purchase', $pid > 0 ? $pid : null, null, $branch, [
                    'invoice_id' => $iid,
                    'repair_reference_state' => $refState,
                ]);
                $stats['broken_reference']++;

                continue;
            }

            if ($iid <= 0) {
                continue;
            }

            if (isset($seenInvoiceIds[$iid])) {
                $this->audit->log('public_commerce_fulfillment_repair_skipped', 'public_commerce_purchase', $pid > 0 ? $pid : null, null, $branch, [
                    'invoice_id' => $iid,
                    'reason' => 'duplicate_invoice_in_batch',
                ]);
                $stats['duplicate_invoice_skipped']++;

                continue;
            }
            $seenInvoiceIds[$iid] = true;

            $result = $this->reconciler->reconcile(
                $iid,
                PublicCommerceFulfillmentReconcilerContract::TRIGGER_INTERNAL_REPAIR_BATCH,
                null
            );

            $this->fulfillmentReconcileRecovery->recordPostReconcileOutcome(
                $iid,
                PublicCommerceFulfillmentReconcilerContract::TRIGGER_INTERNAL_REPAIR_BATCH,
                null,
                $result
            );

            $stats['invoices_reconciled']++;
            $outcome = (string) ($result['outcome'] ?? '');
            $reason = $result['reason'] ?? null;

            if ($outcome === PublicCommerceFulfillmentReconcilerContract::OUTCOME_APPLIED) {
                $this->audit->log('public_commerce_fulfillment_repair_applied', 'public_commerce_purchase', $pid, null, $branch, [
                    'invoice_id' => $iid,
                    'prior_purchase_status' => $result['prior_purchase_status'] ?? null,
                ]);
                $stats['reconcile_applied']++;
            } elseif ($outcome === PublicCommerceFulfillmentReconcilerContract::OUTCOME_STILL_PENDING) {
                $this->audit->log('public_commerce_fulfillment_repair_still_pending', 'public_commerce_purchase', $pid, null, $branch, [
                    'invoice_id' => $iid,
                    'reconcile_reason' => $reason,
                ]);
                $stats['reconcile_still_pending']++;
            } elseif ($outcome === PublicCommerceFulfillmentReconcilerContract::OUTCOME_BLOCKED) {
                $this->audit->log('public_commerce_fulfillment_repair_blocked', 'public_commerce_purchase', $pid, null, $branch, [
                    'invoice_id' => $iid,
                    'reconcile_reason' => $reason,
                ]);
                $stats['reconcile_blocked']++;
            } elseif ($outcome === PublicCommerceFulfillmentReconcilerContract::OUTCOME_SKIPPED) {
                $this->audit->log('public_commerce_fulfillment_repair_skipped', 'public_commerce_purchase', $pid, null, $branch, [
                    'invoice_id' => $iid,
                    'reconcile_reason' => $reason,
                ]);
                $stats['reconcile_skipped']++;
            } else {
                $this->audit->log('public_commerce_fulfillment_repair_skipped', 'public_commerce_purchase', $pid, null, $branch, [
                    'invoice_id' => $iid,
                    'reconcile_outcome' => $outcome,
                    'reconcile_reason' => $reason,
                    'note' => 'noop_no_purchase_or_error',
                ]);
                $stats['reconcile_noop_or_error']++;
            }
        }

        $this->audit->log('public_commerce_fulfillment_repair_batch_finished', 'public_commerce_purchase', null, null, $branchId, $stats);

        return $stats;
    }
}
