<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationScopedBranchAssert;
use Core\Contracts\InvoiceGiftCardRedemptionProvider;
use Core\Contracts\MembershipInvoiceSettlementProvider;
use Core\Contracts\PublicCommerceFulfillmentReconciler;
use Core\Contracts\ReceiptPrintDispatchProvider;
use Modules\Notifications\Services\NotificationService;
use Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService;
use Modules\Sales\Repositories\InvoiceRepository;
use Modules\Sales\Repositories\PaymentRepository;
use Modules\Sales\Repositories\RegisterSessionRepository;
use Modules\Sales\Services\PaymentMethodService;

/**
 * Payment recording. On create, automatically updates invoice paid_amount and status.
 *
 * A-005: `payments.*` policy (partial/overpay/default/receipt_notes) and the hardware **cash-register** flag use
 * {@see SettingsService::getPaymentSettings(null)} / {@see SettingsService::getHardwareSettings(null)} — organization default only,
 * matching Payments + Hardware admin. Receipt footer copy stays branch-effective via {@see SettingsService::getEffectiveReceiptFooterText}.
 *
 * - `payment_recorded` / `payment_refunded` audits snapshot `receipt_notes` (branch-effective footer) and `hardware_use_receipt_printer` (org hardware toggle).
 * - After commit, {@see ReceiptPrintDispatchProvider::dispatchAfterPaymentRecorded} runs when {@see SettingsService::isReceiptPrintingEnabled(null)}.
 *
 * Currency: `payments.currency` is set on insert from the invoice row + same fallback as {@see invoiceCurrencyForAudit}
 * (never from request). Refund rows use the invoice’s canonical currency at refund time.
 *
 * **Branch on create:** After {@see InvoiceRepository::findForUpdate}, payment-method allowlist and register session use `branch_id`
 * from that **locked** invoice row; payment policy and cash-register requirement use org-default settings reads above.
 * Post-commit {@see ReceiptPrintDispatchProvider::dispatchAfterPaymentRecorded} uses the **same** `branch_id` captured from that
 * row at successful transaction end — no fresh `invoiceRepo->find()` for receipt branch selection.
 */
final class PaymentService
{
    private const VALID_STATUSES = ['pending', 'completed', 'failed', 'refunded', 'voided'];

    public function __construct(
        private PaymentRepository $repo,
        private InvoiceRepository $invoiceRepo,
        private RegisterSessionRepository $registerSessions,
        private InvoiceService $invoiceService,
        private InvoiceGiftCardRedemptionProvider $giftCardRedemptionProvider,
        private PaymentMethodService $paymentMethodService,
        private SettingsService $settingsService,
        private AuditService $audit,
        private Database $db,
        private BranchContext $branchContext,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
        private NotificationService $notifications,
        private MembershipInvoiceSettlementProvider $membershipSettlement,
        private ReceiptPrintDispatchProvider $receiptPrintDispatch,
        private PublicCommerceFulfillmentReconcileRecoveryService $publicCommerceFulfillmentReconcileRecovery,
        /** @var callable(): PublicCommerceFulfillmentReconciler */
        private $publicCommerceFulfillmentReconciler
    ) {
    }

    public function create(array $data): int
    {
        $requestedStatus = (string) ($data['status'] ?? 'completed');
        $invoiceIdForDispatch = (int) ($data['invoice_id'] ?? 0);

        $receiptDispatchBranchId = null;
        $id = $this->transactional(function () use ($data, &$receiptDispatchBranchId): int {
            $method = trim((string) ($data['payment_method'] ?? ''));
            $invoiceId = (int) ($data['invoice_id'] ?? 0);
            $this->validateStatus($data['status'] ?? 'completed');
            $amount = round((float) ($data['amount'] ?? 0), 2);
            if (!is_finite($amount) || $amount <= 0) {
                throw new \DomainException('Payment amount must be greater than zero.');
            }
            $inv = $this->invoiceRepo->findForUpdate($invoiceId);
            if (!$inv) {
                throw new \RuntimeException('Invoice not found');
            }
            $branchId = $inv['branch_id'] !== null && $inv['branch_id'] !== '' ? (int) $inv['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($branchId);
            $allowedForForm = $this->paymentMethodService->listForPaymentForm($branchId);
            if ($allowedForForm === []) {
                throw new \DomainException('No active payment methods are available for this invoice branch. Configure payment methods in settings.');
            }
            if (!$this->paymentMethodService->isAllowedForRecordedInvoicePayment($method, $branchId)) {
                throw new \DomainException('Payment method is not allowed for recorded invoice payments, or is invalid for this branch.');
            }
            $data['payment_method'] = $method;
            if (in_array((string) ($inv['status'] ?? ''), ['cancelled', 'refunded'], true)) {
                throw new \DomainException('Cannot record payment for cancelled/refunded invoice.');
            }
            $status = (string) ($data['status'] ?? 'completed');
            $reference = trim((string) ($data['transaction_reference'] ?? ''));
            if ($status === 'completed' && $reference !== '' && $this->repo->existsCompletedByInvoiceAndReferenceInInvoicePlane($invoiceId, $reference)) {
                throw new \DomainException('A completed payment with this reference already exists for the invoice.');
            }
            $currentPaid = round((float) ($inv['paid_amount'] ?? 0), 2);
            $total = round((float) ($inv['total_amount'] ?? 0), 2);
            $balanceDue = round($total - $currentPaid, 2);
            $paymentSettings = $this->settingsService->getPaymentSettings(null);
            if ($status === 'completed') {
                if (!$paymentSettings['allow_overpayments'] && $amount > $balanceDue) {
                    throw new \DomainException('Payment amount cannot exceed invoice balance due.');
                }
                if (!$paymentSettings['allow_partial_payments'] && $amount < $balanceDue) {
                    throw new \DomainException('Full payment required; partial payments are not allowed.');
                }
            }

            $data['amount'] = $amount;
            $data['created_by'] = $this->currentUserId();
            if (($data['payment_method'] ?? '') === 'cash') {
                $hwSettings = $this->settingsService->getHardwareSettings(null);
                if ($hwSettings['use_cash_register']) {
                    if ($branchId === null) {
                        throw new \DomainException('Cash payment requires branch-scoped invoice.');
                    }
                    $openSession = $this->registerSessions->findOpenByBranchForUpdate($branchId);
                    if (!$openSession) {
                        throw new \DomainException('Open register session is required for cash payment in this branch.');
                    }
                    $data['register_session_id'] = (int) $openSession['id'];
                } else {
                    $data['register_session_id'] = null;
                }
            } else {
                $data['register_session_id'] = null;
            }
            if ($status === 'completed' && empty($data['paid_at'])) {
                $data['paid_at'] = date('Y-m-d H:i:s');
            }
            if ($reference === '') {
                $data['transaction_reference'] = null;
            }
            unset($data['currency']);
            $data['currency'] = $this->invoiceCurrencyForAudit($inv, $branchId);
            $id = $this->repo->create($data);
            $this->invoiceService->recomputeInvoiceFinancials($invoiceId);
            $this->membershipSettlement->syncBillingCycleForInvoice($invoiceId);
            $this->membershipSettlement->syncMembershipSaleForInvoice($invoiceId);
            $this->publicCommerceFulfillmentReconcileRecovery->reconcileAndPersistRecoveryIfFailed(
                $invoiceId,
                PublicCommerceFulfillmentReconciler::TRIGGER_INVOICE_SETTLEMENT,
                null,
                ($this->publicCommerceFulfillmentReconciler)()
            );
            $this->setIssuedAtOnFirstPayment($invoiceId, $currentPaid);
            $receiptAudit = $this->receiptPrintSettingsForAudit($branchId);
            $this->audit->log('payment_recorded', 'payment', $id, $this->currentUserId(), $inv['branch_id'] ?? null, [
                'payment' => $data,
                'invoice_id' => $invoiceId,
                'invoice_total' => $total,
                'balance_due_before' => $balanceDue,
                'invoice_currency' => $this->invoiceCurrencyForAudit($inv, $branchId),
                'receipt_notes' => $receiptAudit['receipt_notes'],
                'hardware_use_receipt_printer' => $receiptAudit['hardware_use_receipt_printer'],
            ], 'success', 'payments');
            \slog('info', 'critical_path.payment', 'payment_recorded', [
                'payment_id' => $id,
                'invoice_id' => $invoiceId,
                'branch_id' => $inv['branch_id'] ?? null,
            ]);
            $receiptDispatchBranchId = $branchId;
            return $id;
        }, 'payment create');

        if ($requestedStatus === 'completed' && $invoiceIdForDispatch > 0) {
            if ($this->settingsService->isReceiptPrintingEnabled(null)) {
                try {
                    $this->receiptPrintDispatch->dispatchAfterPaymentRecorded($invoiceIdForDispatch, $id, $receiptDispatchBranchId);
                } catch (\Throwable $e) {
                    slog('error', 'sales.receipt_print_dispatch', $e->getMessage(), [
                        'payment_id' => $id,
                        'invoice_id' => $invoiceIdForDispatch,
                    ]);
                }
            }
        }

        return $id;
    }

    public function refund(int $paymentId, float $amount, ?string $notes = null): int
    {
        return $this->transactional(function () use ($paymentId, $amount, $notes): int {
            if (!is_finite($amount) || $amount <= 0) {
                throw new \DomainException('Refund amount must be greater than zero.');
            }
            $original = $this->repo->findForUpdateInInvoicePlane($paymentId);
            if (!$original) {
                throw new \RuntimeException('Payment not found.');
            }
            if ((string) ($original['entry_type'] ?? 'payment') !== 'payment') {
                throw new \DomainException('Only original payment rows can be refunded.');
            }
            if ((string) ($original['status'] ?? '') !== 'completed') {
                throw new \DomainException('Only completed payments can be refunded.');
            }
            $invoiceId = (int) ($original['invoice_id'] ?? 0);
            $invoice = $this->invoiceRepo->findForUpdate($invoiceId);
            if (!$invoice) {
                throw new \RuntimeException('Invoice not found.');
            }
            $branchIdForOrg = $invoice['branch_id'] !== null && $invoice['branch_id'] !== '' ? (int) $invoice['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchIdForOrg);
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($branchIdForOrg);
            if ((string) ($invoice['status'] ?? '') === 'cancelled') {
                throw new \DomainException('Cannot refund payment for cancelled invoice.');
            }

            $branchIdForInvoice = isset($invoice['branch_id']) && $invoice['branch_id'] !== '' && $invoice['branch_id'] !== null
                ? (int) $invoice['branch_id']
                : null;
            $paymentCurrency = $this->invoiceCurrencyForAudit($invoice, $branchIdForInvoice);

            $alreadyRefunded = $this->repo->getCompletedRefundedTotalForParentPaymentInInvoicePlane($paymentId);
            $originalAmount = round((float) ($original['amount'] ?? 0), 2);
            $remainingRefundable = round($originalAmount - $alreadyRefunded, 2);
            $refundAmount = round($amount, 2);
            if ($refundAmount > $remainingRefundable) {
                throw new \DomainException('Refund amount exceeds remaining refundable amount for this payment.');
            }

            $method = (string) ($original['payment_method'] ?? '');
            $registerSessionId = null;
            if ($method === 'cash') {
                $branchIdForRegister = ($invoice['branch_id'] ?? null) !== null && $invoice['branch_id'] !== '' ? (int) $invoice['branch_id'] : null;
                $hwSettings = $this->settingsService->getHardwareSettings(null);
                if ($hwSettings['use_cash_register']) {
                    if ($branchIdForRegister === null) {
                        throw new \DomainException('Cash refund requires branch-scoped invoice.');
                    }
                    $openSession = $this->registerSessions->findOpenByBranchForUpdate($branchIdForRegister);
                    if (!$openSession) {
                        throw new \DomainException('Open register session is required for cash refund in this branch.');
                    }
                    $registerSessionId = (int) $openSession['id'];
                }
            }

            $refundPaymentId = $this->repo->create([
                'invoice_id' => $invoiceId,
                'register_session_id' => $registerSessionId,
                'entry_type' => 'refund',
                'parent_payment_id' => $paymentId,
                'payment_method' => $method,
                'amount' => $refundAmount,
                'currency' => $paymentCurrency,
                'status' => 'completed',
                'transaction_reference' => 'refund:' . $paymentId . ':' . time(),
                'paid_at' => date('Y-m-d H:i:s'),
                'notes' => $notes,
                'created_by' => $this->currentUserId(),
            ]);

            if ($method === 'gift_card') {
                $giftCardId = $this->extractGiftCardIdFromReference((string) ($original['transaction_reference'] ?? ''));
                if ($giftCardId <= 0) {
                    throw new \DomainException('Cannot resolve gift card id from payment reference.');
                }
                $giftRefundBranch = $invoice['branch_id'] !== null && $invoice['branch_id'] !== '' ? (int) $invoice['branch_id'] : null;
                if ($giftRefundBranch === null || $giftRefundBranch <= 0) {
                    throw new \DomainException('Gift card refund requires branch-scoped invoice.');
                }
                $this->giftCardRedemptionProvider->refundInvoiceRedemption(
                    $invoiceId,
                    $giftCardId,
                    $refundAmount,
                    $giftRefundBranch,
                    $notes,
                    $refundPaymentId
                );
            }

            $this->invoiceService->recomputeInvoiceFinancials($invoiceId);
            $this->membershipSettlement->syncBillingCycleForInvoice($invoiceId);
            $this->membershipSettlement->syncMembershipSaleForInvoice($invoiceId);
            $this->publicCommerceFulfillmentReconcileRecovery->reconcileAndPersistRecoveryIfFailed(
                $invoiceId,
                PublicCommerceFulfillmentReconciler::TRIGGER_PAYMENT_REFUND,
                $this->currentUserId(),
                ($this->publicCommerceFulfillmentReconciler)()
            );
            $receiptAudit = $this->receiptPrintSettingsForAudit($branchIdForInvoice);
            $this->audit->log('payment_refunded', 'payment', $refundPaymentId, $this->currentUserId(), $invoice['branch_id'] ?? null, [
                'original_payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
                'refund_amount' => $refundAmount,
                'payment_method' => $method,
                'remaining_refundable_after' => round($remainingRefundable - $refundAmount, 2),
                'invoice_currency' => $paymentCurrency,
                'receipt_notes' => $receiptAudit['receipt_notes'],
                'hardware_use_receipt_printer' => $receiptAudit['hardware_use_receipt_printer'],
            ], 'success', 'payments');
            \slog('info', 'critical_path.payment', 'payment_refunded', [
                'payment_id' => $refundPaymentId,
                'invoice_id' => $invoiceId,
            ]);
            $branchIdForNotif = $branchIdForInvoice;
            try {
                $this->notifications->create([
                    'branch_id' => $branchIdForNotif,
                    'user_id' => null,
                    'type' => 'payment_refund',
                    'title' => 'Payment refunded',
                    'message' => 'Refund of ' . $refundAmount . ' for payment #' . $paymentId . ' (invoice #' . $invoiceId . ').',
                    'entity_type' => 'payment',
                    'entity_id' => $refundPaymentId,
                ]);
            } catch (\Throwable $notifEx) {
                slog('warning', 'notifications.payment_refund', $notifEx->getMessage(), [
                    'payment_id' => $paymentId,
                    'invoice_id' => $invoiceId,
                ]);
            }
            return $refundPaymentId;
        }, 'payment refund');
    }

    /** Invoice row `currency` when present, else {@see SettingsService::getEffectiveCurrencyCode} for the invoice branch. */
    private function invoiceCurrencyForAudit(array $invoice, ?int $invoiceBranchId): string
    {
        $c = trim((string) ($invoice['currency'] ?? ''));
        if ($c !== '') {
            return strtoupper($c);
        }
        return $this->settingsService->getEffectiveCurrencyCode($invoiceBranchId);
    }

    /**
     * Canonical branch-effective receipt footer + printer expectation for audit payloads (same merge as invoice show).
     *
     * @return array{receipt_notes: string, hardware_use_receipt_printer: bool}
     */
    private function receiptPrintSettingsForAudit(?int $invoiceBranchId): array
    {
        $hwOrg = $this->settingsService->getHardwareSettings(null);
        return [
            'receipt_notes' => $this->settingsService->getEffectiveReceiptFooterText($invoiceBranchId),
            'hardware_use_receipt_printer' => (bool) $hwOrg['use_receipt_printer'],
        ];
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid payment status.');
        }
    }

    private function extractGiftCardIdFromReference(string $reference): int
    {
        if (preg_match('/^gift-card:(\d+)$/', trim($reference), $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Set invoice issued_at when the first completed payment is recorded (paid was 0, now > 0).
     * Only updates if issued_at is not already set; safe for refund/gift-card flows.
     */
    private function setIssuedAtOnFirstPayment(int $invoiceId, float $paidBeforeThisPayment): void
    {
        if ($paidBeforeThisPayment > 0) {
            return;
        }
        $inv = $this->invoiceRepo->find($invoiceId);
        if (!$inv || (float) ($inv['paid_amount'] ?? 0) <= 0) {
            return;
        }
        if (!empty($inv['issued_at'])) {
            return;
        }
        $this->invoiceRepo->update($invoiceId, [
            'issued_at' => date('Y-m-d H:i:s'),
            'updated_by' => $this->currentUserId(),
        ]);
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $callback();
            if ($started) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'sales.payment_transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Payment operation failed.');
        }
    }
}
