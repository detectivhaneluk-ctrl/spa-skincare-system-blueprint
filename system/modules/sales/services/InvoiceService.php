<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;
use Core\Organization\OrganizationScopedBranchAssert;
use Core\Contracts\GiftCardAvailabilityProvider;
use Core\Contracts\InvoiceStockSettlementProvider;
use Core\Contracts\MembershipInvoiceSettlementProvider;
use Core\Contracts\PublicCommerceFulfillmentReconciler;
use Core\Contracts\ReceiptPrintDispatchProvider;
use Core\Contracts\InvoiceGiftCardRedemptionProvider;
use Core\Contracts\ServiceListProvider;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Inventory\Services\InvoiceProductStockBranchContract;
use Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService;
use Modules\Sales\Repositories\InvoiceItemRepository;
use Modules\Sales\Repositories\InvoiceRepository;
use Modules\Sales\Repositories\PaymentRepository;

/**
 * Invoice service with centralized calculation rules.
 *
 * CALCULATION RULES:
 * - Line: line_total = (quantity * unit_price - discount_amount) * (1 + tax_rate/100), rounded to 2 decimals.
 * - Invoice subtotal_amount = sum of all line_total.
 * - Invoice discount_amount = invoice-level discount (reduces subtotal).
 * - Invoice tax_amount = invoice-level tax (adds to amount). Can be 0.
 * - Invoice total_amount = subtotal_amount - discount_amount + tax_amount.
 * - Invoice paid_amount = sum of completed payment amounts (recomputed on payment record).
 * - Balance due = total_amount - paid_amount.
 *
 * Product retail lines (`item_type` = product, `source_id` = products.id): branch pairing vs `invoices.branch_id` is enforced
 * at create/update (see {@see InvoiceProductStockBranchContract}) so settlement does not fail at payment time. Stock follows
 * {@see \Core\Contracts\InvoiceStockSettlementProvider::syncProductStockWithInvoiceSettlement} on each {@see recomputeInvoiceFinancials}
 * — net deduction when {@code paid}, restored when refunds or status move the invoice off fully paid.
 *
 * Service-linked lines (`item_type` = service, `source_id` = services.id):
 * - On create/update, `tax_rate` is overwritten from the service's `vat_rate_id` via {@see VatRateService::getRatePercentById}
 *   when that id resolves to a VAT row (catalog truth). Manual lines keep submitted `tax_rate`.
 * - On create, `currency` is set from {@see SettingsService::getEffectiveCurrencyCode} for the invoice branch (immutable on update).
 */
final class InvoiceService
{
    private const VALID_STATUSES = ['draft', 'open', 'partial', 'paid', 'cancelled', 'refunded'];
    private const EDITABLE_STATUSES = ['draft', 'open', 'partial'];

    public function __construct(
        private InvoiceRepository $repo,
        private InvoiceItemRepository $itemRepo,
        private PaymentRepository $paymentRepo,
        private AuditService $audit,
        private Database $db,
        private InvoiceGiftCardRedemptionProvider $giftCardRedemptionProvider,
        private GiftCardAvailabilityProvider $giftCardAvailability,
        private RequestContextHolder $contextHolder,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
        private ServiceListProvider $serviceList,
        private VatRateService $vatRateService,
        private SettingsService $settingsService,
        private MembershipInvoiceSettlementProvider $membershipSettlement,
        private InvoiceStockSettlementProvider $invoiceStockSettlement,
        private ReceiptPrintDispatchProvider $receiptPrintDispatch,
        private ProductRepository $productRepo,
        private PublicCommerceFulfillmentReconcileRecoveryService $publicCommerceFulfillmentReconcileRecovery,
        private CashierLineDomainEffectsApplier $cashierLineDomainEffects,
        /** @var callable(): PublicCommerceFulfillmentReconciler */
        private $publicCommerceFulfillmentReconciler,
        private AuthorizerInterface $authorizer,
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $ctx = $this->contextHolder->requireContext();
            $resolved = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::INVOICE_CREATE, ResourceRef::collection('invoice'));
            if (!isset($data['branch_id']) || $data['branch_id'] === null || $data['branch_id'] === '') {
                $data['branch_id'] = $resolved['branch_id'];
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization(
                $this->nullableInvoiceBranchId($data['branch_id'] ?? null)
            );
            unset($data['currency']);
            $branchIdForCurrency = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
                ? (int) $data['branch_id']
                : null;
            $data['currency'] = $this->settingsService->getEffectiveCurrencyCode($branchIdForCurrency);
            $data = $this->applyCanonicalTaxRatesForServiceLines($data);
            $this->validateStatus($data['status'] ?? 'draft');
            $this->assertValidMoneyInput($data);
            $this->assertInvoiceItemsSatisfyProductStockBranchContract(
                $this->nullableInvoiceBranchId($data['branch_id'] ?? null),
                $data['items'] ?? []
            );
            $userId = $this->currentUserId();
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;
            $data['invoice_number'] = $data['invoice_number'] ?? $this->repo->allocateNextInvoiceNumber();
            $data = $this->computeTotals($data);
            $id = $this->repo->create($data);
            $createdLineIds = [];
            if (!empty($data['items'])) {
                foreach ($data['items'] as $i => $item) {
                    $item['invoice_id'] = $id;
                    $item['sort_order'] = $i;
                    $item['line_total'] = $this->computeLineTotal($item);
                    $createdLineIds[] = $this->itemRepo->create($item);
                }
            }
            $this->recomputeInvoiceFinancials($id);
            $invRow = $this->repo->find($id);
            if ($invRow !== null && $createdLineIds !== []) {
                $this->cashierLineDomainEffects->applyForNewInvoice($invRow, $data['items'] ?? [], $createdLineIds);
            }
            $this->audit->log('invoice_created', 'invoice', $id, $userId, $data['branch_id'] ?? null, [
                'invoice' => $data,
            ]);
            return $id;
        }, 'invoice create');
    }

    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $current = $this->repo->findForUpdate($id);
            if (!$current) throw new \RuntimeException('Invoice not found');
            $ctx = $this->contextHolder->requireContext();
            $resolved = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::INVOICE_EDIT, ResourceRef::instance('invoice', $id));
            $currentBranchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            if ($currentBranchId !== null && $currentBranchId !== $resolved['branch_id']) {
                throw new \DomainException('Invoice branch does not match current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($currentBranchId);
            if (!in_array($current['status'], self::EDITABLE_STATUSES, true)) {
                throw new \DomainException('Cannot edit invoice in status: ' . $current['status']);
            }
            unset($data['currency']);
            $data = $this->applyCanonicalTaxRatesForServiceLines($data);
            $this->validateStatus($data['status'] ?? $current['status']);
            $this->assertValidMoneyInput($data);

            $effectiveInvoiceBranch = $this->effectiveInvoiceBranchAfterMerge($current, $data);
            $lineItemsForContract = array_key_exists('items', $data)
                ? $data['items']
                : $this->itemRepo->getByInvoiceId($id);
            $this->assertInvoiceItemsSatisfyProductStockBranchContract($effectiveInvoiceBranch, $lineItemsForContract);

            $data['updated_by'] = $this->currentUserId();
            $data = $this->computeTotals($data);

            $paid = round($this->paymentRepo->getCompletedTotalByInvoiceIdInInvoicePlane($id), 2);
            $newTotal = round((float) $data['total_amount'], 2);
            if ($paid > 0 && $newTotal < $paid) {
                throw new \DomainException('Invoice total cannot be reduced below the amount already paid.');
            }

            $this->repo->update($id, $data);
            if (isset($data['items'])) {
                $this->itemRepo->deleteByInvoiceId($id);
                foreach ($data['items'] as $i => $item) {
                    $item['invoice_id'] = $id;
                    $item['sort_order'] = $i;
                    $item['line_total'] = $this->computeLineTotal($item);
                    $this->itemRepo->create($item);
                }
                $this->assertInvoiceTotalsConsistentWithItems($id, $data);
            }
            $this->recomputeInvoiceFinancials($id);
            $this->membershipSettlement->syncBillingCycleForInvoice($id);
            $this->membershipSettlement->syncMembershipSaleForInvoice($id);
            $this->publicCommerceFulfillmentReconcileRecovery->reconcileAndPersistRecoveryIfFailed(
                $id,
                PublicCommerceFulfillmentReconciler::TRIGGER_INVOICE_SETTLEMENT,
                null,
                ($this->publicCommerceFulfillmentReconciler)()
            );
            $this->audit->log('invoice_updated', 'invoice', $id, $this->currentUserId(), $current['branch_id'] ?? null, [
                'before' => $current,
                'after' => array_merge($current, $data),
            ]);
        }, 'invoice update');
    }

    public function cancel(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $inv = $this->repo->find($id);
            if (!$inv) throw new \RuntimeException('Invoice not found');
            $ctx = $this->contextHolder->requireContext();
            $resolved = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::INVOICE_VOID, ResourceRef::instance('invoice', $id));
            $invBranchId = $inv['branch_id'] !== null && $inv['branch_id'] !== '' ? (int) $inv['branch_id'] : null;
            if ($invBranchId !== null && $invBranchId !== $resolved['branch_id']) {
                throw new \DomainException('Invoice branch does not match current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($invBranchId);
            if (($inv['status'] ?? '') === 'cancelled') {
                return;
            }
            if (($inv['status'] ?? '') === 'refunded') {
                throw new \DomainException('Refunded invoice cannot be cancelled.');
            }
            if ((float) ($inv['paid_amount'] ?? 0) > 0) {
                throw new \DomainException('Invoice with posted payments cannot be cancelled.');
            }
            $this->repo->update($id, ['status' => 'cancelled', 'updated_by' => $this->currentUserId()]);
            $this->membershipSettlement->syncBillingCycleForInvoice($id);
            $this->membershipSettlement->syncMembershipSaleForInvoice($id);
            $this->audit->log('invoice_cancelled', 'invoice', $id, $this->currentUserId(), $inv['branch_id'] ?? null, [
                'invoice' => $inv,
            ]);
        }, 'invoice cancel');
    }

    public function delete(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $inv = $this->repo->find($id);
            if (!$inv) throw new \RuntimeException('Invoice not found');
            $ctx = $this->contextHolder->requireContext();
            $resolved = $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::INVOICE_DELETE, ResourceRef::instance('invoice', $id));
            $invBranchId = $inv['branch_id'] !== null && $inv['branch_id'] !== '' ? (int) $inv['branch_id'] : null;
            if ($invBranchId !== null && $invBranchId !== $resolved['branch_id']) {
                throw new \DomainException('Invoice branch does not match current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($invBranchId);
            if (in_array((string) ($inv['status'] ?? ''), ['paid', 'partial', 'refunded'], true) || (float) ($inv['paid_amount'] ?? 0) > 0) {
                throw new \DomainException('Financially posted invoice cannot be deleted.');
            }
            $this->repo->softDelete($id);
            $this->membershipSettlement->syncBillingCycleForInvoice($id);
            $this->membershipSettlement->syncMembershipSaleForInvoice($id);
            $this->audit->log('invoice_deleted', 'invoice', $id, $this->currentUserId(), $inv['branch_id'] ?? null, [
                'invoice' => $inv,
            ]);
        }, 'invoice delete');
    }

    public function recomputePaidAmount(int $invoiceId): void
    {
        $this->recomputeInvoiceFinancials($invoiceId);
    }

    /**
     * Single recompute path: header **subtotal_amount** / **total_amount** from persisted line `line_total` rows
     * (+ invoice-level `discount_amount` / `tax_amount`), then **paid_amount** and **status** from completed payments/refunds.
     *
     * @return array{invoice_id:int,total_amount:float,paid_amount:float,balance_due:float,status:string}|null
     */
    public function recomputeInvoiceFinancials(int $invoiceId): ?array
    {
        $pdo = $this->db->connection();
        $startedHere = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedHere = true;
        }
        try {
            $inv = $this->repo->find($invoiceId);
            if (!$inv) {
                if ($startedHere && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                return null;
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization(
                $inv['branch_id'] !== null && $inv['branch_id'] !== '' ? (int) $inv['branch_id'] : null
            );
            $items = $this->itemRepo->getByInvoiceId($invoiceId);
            $lineSum = 0.0;
            foreach ($items as $row) {
                $lineSum += (float) ($row['line_total'] ?? 0);
            }
            $newSubtotal = round($lineSum, 2);
            $discount = round((float) ($inv['discount_amount'] ?? 0), 2);
            $tax = round((float) ($inv['tax_amount'] ?? 0), 2);
            $newTotal = round($newSubtotal - $discount + $tax, 2);
            if ($newTotal < 0) {
                throw new \DomainException('Invoice totals are inconsistent: recomputed total from line items is negative.');
            }

            $oldSubtotal = round((float) ($inv['subtotal_amount'] ?? 0), 2);
            $oldTotal = round((float) ($inv['total_amount'] ?? 0), 2);

            $paid = round($this->paymentRepo->getCompletedTotalByInvoiceIdInInvoicePlane($invoiceId), 2);
            $hasRefund = $this->paymentRepo->hasCompletedRefundForInvoiceInInvoicePlane($invoiceId);
            $status = $this->deriveStatusFromPaid($newTotal, $paid, (string) ($inv['status'] ?? 'draft'), $hasRefund);

            $branchId = $inv['branch_id'] !== null && $inv['branch_id'] !== '' ? (int) $inv['branch_id'] : null;
            if (abs($oldSubtotal - $newSubtotal) > 0.005 || abs($oldTotal - $newTotal) > 0.005) {
                $this->audit->log('invoice_financials_recomputed', 'invoice', $invoiceId, $this->currentUserId(), $branchId, [
                    'before_subtotal_amount' => $oldSubtotal,
                    'after_subtotal_amount' => $newSubtotal,
                    'before_total_amount' => $oldTotal,
                    'after_total_amount' => $newTotal,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                ]);
            }

            $this->repo->update($invoiceId, [
                'subtotal_amount' => $newSubtotal,
                'total_amount' => $newTotal,
                'paid_amount' => $paid,
                'status' => $status,
                'updated_by' => $this->currentUserId(),
            ]);

            $this->invoiceStockSettlement->syncProductStockWithInvoiceSettlement($invoiceId);

            $result = [
                'invoice_id' => $invoiceId,
                'total_amount' => $newTotal,
                'paid_amount' => $paid,
                'balance_due' => $this->calculateBalanceDue($newTotal, $paid),
                'status' => $status,
            ];
            if ($startedHere) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function redeemGiftCardPayment(
        int $invoiceId,
        int $giftCardId,
        float $amount,
        ?string $notes = null
    ): int {
        if (!is_finite($amount) || $amount <= 0) {
            throw new \DomainException('Redeem amount must be greater than zero.');
        }

        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }

            $invoice = $this->repo->findForUpdate($invoiceId);
            if (!$invoice) {
                throw new \RuntimeException('Invoice not found.');
            }
            $ctx = $this->contextHolder->requireContext();
            $resolved = $ctx->requireResolvedTenant();
            $invoiceBranchIdForCheck = $invoice['branch_id'] !== null && $invoice['branch_id'] !== '' ? (int) $invoice['branch_id'] : null;
            if ($invoiceBranchIdForCheck !== null && $invoiceBranchIdForCheck !== $resolved['branch_id']) {
                throw new \DomainException('Invoice branch does not match current branch context.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($invoiceBranchIdForCheck);
            if (empty($invoice['client_id'])) {
                throw new \DomainException('Invoice must have a client before redeeming gift cards.');
            }
            $invoiceBranchId = $invoice['branch_id'] !== null && $invoice['branch_id'] !== '' ? (int) $invoice['branch_id'] : null;
            if ($invoiceBranchId === null || $invoiceBranchId <= 0) {
                throw new \DomainException('Invoice branch is required for gift card redemption.');
            }
            if (in_array((string) ($invoice['status'] ?? ''), ['cancelled', 'refunded'], true)) {
                throw new \DomainException('Gift card redemption is not allowed for cancelled/refunded invoices.');
            }

            $balanceDue = round((float) $invoice['total_amount'] - (float) $invoice['paid_amount'], 2);
            if ($balanceDue <= 0) {
                throw new \DomainException('Invoice has no remaining balance due.');
            }
            if ($amount > $balanceDue) {
                throw new \DomainException('Redeem amount cannot exceed invoice balance due.');
            }

            if ($this->giftCardRedemptionProvider->hasInvoiceRedemption($invoiceId, $giftCardId)) {
                throw new \DomainException('This gift card is already redeemed for this invoice.');
            }

            $summary = $this->giftCardAvailability->getBalanceSummary($giftCardId);
            if ($summary === null) {
                throw new \RuntimeException('Gift card not found.');
            }
            $invoiceCurrency = strtoupper(trim((string) ($invoice['currency'] ?? '')));
            if ($invoiceCurrency === '') {
                $invoiceCurrency = $this->settingsService->getEffectiveCurrencyCode(
                    $invoice['branch_id'] !== null && $invoice['branch_id'] !== '' ? (int) $invoice['branch_id'] : null
                );
            }
            $cardCurrency = strtoupper(trim((string) ($summary['currency'] ?? '')));
            if ($cardCurrency === '') {
                $cardCurrency = 'USD';
            }
            if ($invoiceCurrency !== $cardCurrency) {
                throw new \DomainException(
                    'Gift card currency (' . $cardCurrency . ') does not match invoice currency (' . $invoiceCurrency . ').'
                );
            }

            $this->giftCardRedemptionProvider->redeemForInvoice(
                $invoiceId,
                (int) $invoice['client_id'],
                $giftCardId,
                $amount,
                $invoiceBranchId,
                $notes
            );

            $paymentId = $this->paymentRepo->create([
                'invoice_id' => $invoiceId,
                'payment_method' => 'gift_card',
                'amount' => round($amount, 2),
                'currency' => $invoiceCurrency,
                'status' => 'completed',
                'transaction_reference' => 'gift-card:' . $giftCardId,
                'paid_at' => date('Y-m-d H:i:s'),
                'notes' => $notes,
                'created_by' => $this->currentUserId(),
            ]);

            $this->recomputePaidAmount($invoiceId);
            $this->membershipSettlement->syncBillingCycleForInvoice($invoiceId);
            $this->membershipSettlement->syncMembershipSaleForInvoice($invoiceId);
            $this->publicCommerceFulfillmentReconcileRecovery->reconcileAndPersistRecoveryIfFailed(
                $invoiceId,
                PublicCommerceFulfillmentReconciler::TRIGGER_INVOICE_SETTLEMENT,
                null,
                ($this->publicCommerceFulfillmentReconciler)()
            );
            $this->setIssuedAtOnFirstPaymentIfNeeded($invoiceId, (float) ($invoice['paid_amount'] ?? 0));

            $updated = $this->repo->find($invoiceId);
            $branchId = $invoice['branch_id'] !== null && $invoice['branch_id'] !== '' ? (int) $invoice['branch_id'] : null;
            $this->audit->log('invoice_gift_card_redeemed', 'invoice', $invoiceId, $this->currentUserId(), $branchId, [
                'invoice_id' => $invoiceId,
                'gift_card_id' => $giftCardId,
                'amount' => round($amount, 2),
                'payment_id' => $paymentId,
                'balance_due_before' => $balanceDue,
                'balance_due_after' => $updated ? max(0.0, round((float) $updated['total_amount'] - (float) $updated['paid_amount'], 2)) : null,
                'invoice_currency' => $invoiceCurrency,
                'gift_card_currency' => $cardCurrency,
                'receipt_notes' => $this->settingsService->getEffectiveReceiptFooterText($branchId),
                'hardware_use_receipt_printer' => $this->settingsService->isReceiptPrintingEnabled(null),
            ]);

            if ($started) {
                $pdo->commit();
            }

            if ($this->settingsService->isReceiptPrintingEnabled(null)) {
                try {
                    $this->receiptPrintDispatch->dispatchAfterPaymentRecorded($invoiceId, $paymentId, $branchId);
                } catch (\Throwable $e) {
                    slog('error', 'sales.receipt_print_dispatch', $e->getMessage(), [
                        'payment_id' => $paymentId,
                        'invoice_id' => $invoiceId,
                        'context' => 'gift_card_payment',
                    ]);
                }
            }

            return $paymentId;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Failed to redeem gift card for invoice.');
        }
    }

    /**
     * For each service line with a resolvable service and non-empty vat_rate_id, set line tax_rate from vat_rates.rate_percent.
     * Lookup is **by `vat_rates.id` only** (matches `services.vat_rate_id`); no invoice-branch overlay — intentional catalog-by-FK semantics.
     * Orphan vat_rate_id (no row): throws — prevents silent wrong totals.
     * Service missing / no vat_rate_id: leaves submitted tax_rate (often 0).
     */
    private function applyCanonicalTaxRatesForServiceLines(array $data): array
    {
        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return $data;
        }
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['item_type'] ?? '') !== 'service') {
                continue;
            }
            $sourceId = isset($item['source_id']) ? (int) $item['source_id'] : 0;
            if ($sourceId <= 0) {
                continue;
            }
            $svc = $this->serviceList->find($sourceId);
            if ($svc === null) {
                continue;
            }
            $vrid = isset($svc['vat_rate_id']) && $svc['vat_rate_id'] !== null ? (int) $svc['vat_rate_id'] : 0;
            if ($vrid <= 0) {
                continue;
            }
            $pct = $this->vatRateService->getRatePercentById($vrid);
            if ($pct === null) {
                throw new \DomainException(
                    'Service #' . $sourceId . ' references VAT rate id ' . $vrid . ' which does not exist. Update the service or VAT catalog.'
                );
            }
            $items[$i]['tax_rate'] = round((float) $pct, 2);
        }
        $data['items'] = $items;
        return $data;
    }

    public function computeLineTotal(array $item): float
    {
        $qty = (float) ($item['quantity'] ?? 0);
        $unit = (float) ($item['unit_price'] ?? 0);
        $disc = (float) ($item['discount_amount'] ?? 0);
        $taxRate = (float) ($item['tax_rate'] ?? 0);
        $sub = $qty * $unit - $disc;
        return round($sub * (1 + $taxRate / 100), 2);
    }

    private function computeTotals(array $data): array
    {
        $subtotal = 0.0;
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $subtotal += $this->computeLineTotal($item);
            }
        }
        $discount = (float) ($data['discount_amount'] ?? 0);
        $tax = (float) ($data['tax_amount'] ?? 0);
        $total = $subtotal - $discount + $tax;
        if ($total < 0) {
            throw new \DomainException('Invoice total cannot be negative.');
        }
        $data['subtotal_amount'] = round($subtotal, 2);
        $data['discount_amount'] = round($discount, 2);
        $data['tax_amount'] = round($tax, 2);
        $data['total_amount'] = round($total, 2);
        if (array_key_exists('paid_amount', $data)) {
            if (!is_finite((float) $data['paid_amount']) || (float) $data['paid_amount'] < 0) {
                throw new \DomainException('Invoice paid_amount cannot be negative.');
            }
            $data['paid_amount'] = round((float) $data['paid_amount'], 2);
        }
        $paid = (float) ($data['paid_amount'] ?? 0);
        $data['status'] = $this->deriveStatusFromPaid($total, $paid);
        return $data;
    }

    private function deriveStatusFromPaid(
        float $total,
        float $paid,
        ?string $currentStatus = null,
        bool $hasCompletedRefund = false
    ): string
    {
        if ((string) $currentStatus === 'cancelled') {
            return 'cancelled';
        }
        if ($total <= 0) return 'draft';
        if ($paid >= $total) return 'paid';
        if ($paid > 0) return 'partial';
        if ($hasCompletedRefund) return 'refunded';
        return 'open';
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid invoice status.');
        }
    }

    private function assertValidMoneyInput(array $data): void
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new \DomainException('Invoice requires at least one line item.');
        }
        $subtotal = 0.0;
        foreach ($items as $idx => $item) {
            $line = $idx + 1;
            $qty = (float) ($item['quantity'] ?? 0);
            $unit = (float) ($item['unit_price'] ?? 0);
            $discount = (float) ($item['discount_amount'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);
            if (!is_finite($qty) || $qty <= 0) {
                throw new \DomainException('Line #' . $line . ': quantity must be greater than zero.');
            }
            if (!is_finite($unit) || $unit < 0) {
                throw new \DomainException('Line #' . $line . ': unit price cannot be negative.');
            }
            if (!is_finite($discount) || $discount < 0) {
                throw new \DomainException('Line #' . $line . ': discount cannot be negative.');
            }
            if (!is_finite($taxRate) || $taxRate < 0 || $taxRate > 100) {
                throw new \DomainException('Line #' . $line . ': tax rate must be between 0 and 100.');
            }
            $lineBase = $qty * $unit - $discount;
            if ($lineBase < 0) {
                throw new \DomainException('Line #' . $line . ': discount cannot exceed line subtotal.');
            }
            $lineTotal = $lineBase * (1 + ($taxRate / 100));
            if (!is_finite($lineTotal) || $lineTotal < 0) {
                throw new \DomainException('Line #' . $line . ': computed line total is invalid.');
            }
            $subtotal += $lineTotal;
        }

        $invoiceDiscount = (float) ($data['discount_amount'] ?? 0);
        $invoiceTax = (float) ($data['tax_amount'] ?? 0);
        if (!is_finite($invoiceDiscount) || $invoiceDiscount < 0) {
            throw new \DomainException('Invoice discount cannot be negative.');
        }
        if (!is_finite($invoiceTax) || $invoiceTax < 0) {
            throw new \DomainException('Invoice tax cannot be negative.');
        }
        if ($invoiceDiscount > $subtotal) {
            throw new \DomainException('Invoice discount cannot exceed subtotal.');
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function assertInvoiceItemsSatisfyProductStockBranchContract(?int $invoiceBranchId, array $items): void
    {
        foreach ($items as $idx => $item) {
            if (!is_array($item) || ($item['item_type'] ?? '') !== 'product') {
                continue;
            }
            $lineNo = $idx + 1;
            $pid = isset($item['source_id']) ? (int) $item['source_id'] : 0;
            if ($pid <= 0) {
                throw new \DomainException('Line #' . $lineNo . ': product line requires a valid product.');
            }
            $product = $this->productRepo->findForInvoiceProductLineAssignmentContractInResolvedOrg($pid, $invoiceBranchId);
            if (!$product) {
                throw new \DomainException('Line #' . $lineNo . ': product #' . $pid . ' not found or deleted.');
            }
            $productBranch = isset($product['branch_id']) && $product['branch_id'] !== '' && $product['branch_id'] !== null
                ? (int) $product['branch_id']
                : null;
            InvoiceProductStockBranchContract::assertProductAssignableForInvoiceSettlement(
                $invoiceBranchId,
                $productBranch,
                $pid,
                'Line #' . $lineNo
            );
        }
    }

    private function effectiveInvoiceBranchAfterMerge(array $current, array $data): ?int
    {
        if (array_key_exists('branch_id', $data)) {
            return $this->nullableInvoiceBranchId($data['branch_id']);
        }

        return $this->nullableInvoiceBranchId($current['branch_id'] ?? null);
    }

    private function nullableInvoiceBranchId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    private function calculateBalanceDue(float $total, float $paid): float
    {
        return round($total - $paid, 2);
    }

    /**
     * Set invoice issued_at when the first completed payment is recorded (paid was 0, now > 0).
     * Used by gift-card redemption path; PaymentService sets it for regular payments.
     */
    private function setIssuedAtOnFirstPaymentIfNeeded(int $invoiceId, float $paidBeforeThisPayment): void
    {
        if ($paidBeforeThisPayment > 0) {
            return;
        }
        $inv = $this->repo->find($invoiceId);
        if (!$inv || (float) ($inv['paid_amount'] ?? 0) <= 0) {
            return;
        }
        if (!empty($inv['issued_at'])) {
            return;
        }
        $this->repo->update($invoiceId, [
            'issued_at' => date('Y-m-d H:i:s'),
            'updated_by' => $this->currentUserId(),
        ]);
    }

    /**
     * Verifies that stored invoice total matches the sum of line items (subtotal - discount + tax).
     * Called in update path only; throws if drift is detected.
     */
    private function assertInvoiceTotalsConsistentWithItems(int $invoiceId, array $data): void
    {
        $items = $this->itemRepo->getByInvoiceId($invoiceId);
        $subtotal = 0.0;
        foreach ($items as $row) {
            $subtotal += (float) ($row['line_total'] ?? 0);
        }
        $discount = (float) ($data['discount_amount'] ?? 0);
        $tax = (float) ($data['tax_amount'] ?? 0);
        $expectedTotal = round($subtotal - $discount + $tax, 2);
        $storedTotal = round((float) ($data['total_amount'] ?? 0), 2);
        if (abs($expectedTotal - $storedTotal) > 0.01) {
            throw new \DomainException('Invoice total is inconsistent with line items.');
        }
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
            slog('error', 'sales.invoice_transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Invoice operation failed.');
        }
    }
}
