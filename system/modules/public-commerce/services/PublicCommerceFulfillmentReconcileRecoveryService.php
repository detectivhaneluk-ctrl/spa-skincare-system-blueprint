<?php

declare(strict_types=1);

namespace Modules\PublicCommerce\Services;

use Core\Audit\AuditService;
use Core\Errors\AccessDeniedException;
use Core\Contracts\PublicCommerceFulfillmentReconciler as PublicCommerceFulfillmentReconcilerContract;
use Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository;
use Modules\Sales\Repositories\InvoiceRepository;

/**
 * Persists durable recovery state when sales-layer hooks invoke fulfillment reconcile after financial commits
 * and reconcile reports {@see PublicCommerceFulfillmentReconcilerContract::OUTCOME_ERROR} or throws unexpectedly.
 */
final class PublicCommerceFulfillmentReconcileRecoveryService
{
    private const ERROR_MAX_LEN = 8000;

    public function __construct(
        private PublicCommercePurchaseRepository $purchases,
        private InvoiceRepository $invoiceRepo,
        private AuditService $audit,
    ) {
    }

    public function reconcileAndPersistRecoveryIfFailed(
        int $invoiceId,
        string $triggerSource,
        ?int $staffActorId,
        PublicCommerceFulfillmentReconcilerContract $reconciler,
    ): void {
        try {
            $result = $reconciler->reconcile($invoiceId, $triggerSource, $staffActorId);
        } catch (\Throwable $e) {
            $this->persistRecovery($invoiceId, $triggerSource, $staffActorId, 'throwable', $e->getMessage());

            return;
        }

        $this->recordPostReconcileOutcome($invoiceId, $triggerSource, $staffActorId, $result);
    }

    /**
     * After any {@see PublicCommerceFulfillmentReconcilerContract::reconcile} call: persist durable recovery on
     * {@see PublicCommerceFulfillmentReconcilerContract::OUTCOME_ERROR}, otherwise clear recovery for the purchase row.
     * Use from public finalize and staff sync so outcomes stay aligned with {@see reconcileAndPersistRecoveryIfFailed()}.
     *
     * @param array<string, mixed> $result
     */
    public function recordPostReconcileOutcome(
        int $invoiceId,
        string $triggerSource,
        ?int $staffActorId,
        array $result,
    ): void {
        if (($result['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_ERROR) {
            $detail = (string) ($result['error_detail'] ?? $result['reason'] ?? 'error');
            $this->persistRecovery($invoiceId, $triggerSource, $staffActorId, 'outcome_error', $detail);

            return;
        }

        if (!$this->shouldClearRecoveryAfterReconcile($result)) {
            return;
        }

        $pid = (int) ($result['purchase_id'] ?? 0);
        if ($pid > 0) {
            $this->purchases->clearFulfillmentReconcileRecovery($pid);
        }
    }

    /**
     * Only drop recovery when reconciliation proves fulfillment is in a non-error terminal-good state.
     * Skipped / still-pending outcomes do not imply the prior failure is repaired (H-001 fail-closed).
     *
     * @param array<string, mixed> $result
     */
    private function shouldClearRecoveryAfterReconcile(array $result): bool
    {
        $outcome = (string) ($result['outcome'] ?? '');
        if ($outcome === PublicCommerceFulfillmentReconcilerContract::OUTCOME_APPLIED) {
            return true;
        }
        if ($outcome === PublicCommerceFulfillmentReconcilerContract::OUTCOME_REVERSED) {
            return true;
        }

        return $outcome === PublicCommerceFulfillmentReconcilerContract::OUTCOME_SKIPPED
            && (($result['reason'] ?? '') === PublicCommerceFulfillmentReconcilerContract::REASON_ALREADY_FULFILLED);
    }

    /**
     * When {@see PublicCommerceFulfillmentReconcilerContract::reconcile} throws outside the recovery service wrapper.
     */
    public function persistRecoveryAfterReconcileThrowable(
        int $invoiceId,
        string $triggerSource,
        ?int $staffActorId,
        \Throwable $e,
    ): void {
        $this->persistRecovery($invoiceId, $triggerSource, $staffActorId, 'throwable', $e->getMessage());
    }

    private function persistRecovery(
        int $invoiceId,
        string $triggerSource,
        ?int $staffActorId,
        string $kind,
        string $message,
    ): void {
        $msg = $this->truncate($message);
        try {
            $inv = $this->invoiceRepo->find($invoiceId);
        } catch (AccessDeniedException) {
            $pin = $this->purchases->findBranchIdPinByInvoiceId($invoiceId);
            $inv = ($pin !== null && $pin > 0)
                ? $this->invoiceRepo->findForPublicCommerceCorrelatedBranch($invoiceId, $pin)
                : null;
        }
        $purchase = $inv !== null ? $this->purchases->findCorrelatedToInvoiceRow($inv, $invoiceId) : null;
        $pid = $purchase !== null ? (int) ($purchase['id'] ?? 0) : 0;
        $branchId = $purchase !== null && (int) ($purchase['branch_id'] ?? 0) > 0
            ? (int) ($purchase['branch_id'] ?? 0)
            : null;

        if ($pid > 0) {
            $this->purchases->setFulfillmentReconcileRecovery($pid, $triggerSource, $kind . ': ' . $msg);
        }

        $this->audit->log('public_commerce_fulfillment_reconcile_recovery_pending', 'public_commerce_purchase', $pid > 0 ? $pid : null, $staffActorId, $branchId, [
            'invoice_id' => $invoiceId,
            'trigger' => $triggerSource,
            'failure_kind' => $kind,
            'error_detail' => $msg,
            'public_commerce_purchase_id' => $pid > 0 ? $pid : null,
        ]);
    }

    private function truncate(string $s): string
    {
        if (strlen($s) <= self::ERROR_MAX_LEN) {
            return $s;
        }

        return substr($s, 0, self::ERROR_MAX_LEN);
    }
}
