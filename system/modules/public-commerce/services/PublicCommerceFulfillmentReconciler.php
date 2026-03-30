<?php

declare(strict_types=1);

namespace Modules\PublicCommerce\Services;

use Core\App\Database;
use Core\Audit\AuditService;
use Core\Errors\AccessDeniedException;
use Core\Contracts\PublicCommerceFulfillmentReconciler as PublicCommerceFulfillmentReconcilerContract;
use Modules\GiftCards\Services\GiftCardService;
use Modules\Memberships\Repositories\MembershipSaleRepository;
use Modules\Memberships\Services\MembershipService;
use Modules\Packages\Services\PackageService;
use Modules\Packages\Support\PackageEntitlementSnapshot;
use Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository;
use Modules\Sales\Repositories\InvoiceRepository;

/**
 * Authoritative fulfillment reconciliation for public-commerce purchases linked to an invoice.
 */
final class PublicCommerceFulfillmentReconciler implements PublicCommerceFulfillmentReconcilerContract
{
    private const PURCHASE_AWAITING_VERIFICATION = 'awaiting_verification';

    private const PURCHASE_PAID = 'paid';

    private const PURCHASE_FAILED = 'failed';

    private const PURCHASE_CANCELLED = 'cancelled';

    public function __construct(
        private Database $db,
        private AuditService $audit,
        private InvoiceRepository $invoiceRepo,
        private PublicCommercePurchaseRepository $purchases,
        private MembershipSaleRepository $membershipSales,
        private PackageService $packageService,
        private GiftCardService $giftCardService,
        private MembershipService $membershipService,
    ) {
    }

    public function reconcile(int $invoiceId, string $triggerSource, ?int $staffActorId = null): array
    {
        if ($triggerSource === PublicCommerceFulfillmentReconcilerContract::TRIGGER_PAYMENT_REFUND) {
            return $this->reconcileAfterPaymentRefund($invoiceId, $triggerSource, $staffActorId);
        }

        $base = [
            'invoice_id' => $invoiceId,
            'trigger' => $triggerSource,
        ];
        if ($invoiceId <= 0) {
            return array_merge($base, ['outcome' => self::OUTCOME_NOOP_NO_PURCHASE, 'reason' => null]);
        }

        $inv = $this->loadInvoiceRowForPublicCommerceReconcile($invoiceId);
        if ($inv === null) {
            return array_merge($base, ['outcome' => self::OUTCOME_NOOP_NO_PURCHASE, 'reason' => null]);
        }

        $purchase = $this->purchases->findCorrelatedToInvoiceRow($inv, $invoiceId);
        if ($purchase === null) {
            return array_merge($base, ['outcome' => self::OUTCOME_NOOP_NO_PURCHASE, 'reason' => null]);
        }

        $pid = (int) ($purchase['id'] ?? 0);
        $branchIdForAudit = (int) ($purchase['branch_id'] ?? 0) > 0 ? (int) ($purchase['branch_id'] ?? 0) : null;
        $actorForPurchaseEntity = $staffActorId;

        $this->audit->log('public_commerce_fulfillment_reconcile_requested', 'public_commerce_purchase', $pid, $actorForPurchaseEntity, $branchIdForAudit, [
            'invoice_id' => $invoiceId,
            'trigger' => $triggerSource,
            'public_commerce_purchase_id' => $pid,
            'staff_actor_id' => $staffActorId,
        ]);

        if ((string) ($inv['status'] ?? '') !== 'paid') {
            $this->audit->log('public_commerce_fulfillment_reconcile_skipped', 'public_commerce_purchase', $pid, $actorForPurchaseEntity, $branchIdForAudit, [
                'invoice_id' => $invoiceId,
                'trigger' => $triggerSource,
                'reason' => self::REASON_INVOICE_NOT_PAID,
                'invoice_status' => (string) ($inv['status'] ?? ''),
            ]);

            return array_merge($base, [
                'outcome' => self::OUTCOME_SKIPPED,
                'reason' => self::REASON_INVOICE_NOT_PAID,
                'purchase_id' => $pid,
            ]);
        }

        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $row = $this->purchases->findForUpdateCorrelatedToInvoiceRow($inv, $invoiceId);
            if ($row === null) {
                $this->audit->log('public_commerce_fulfillment_reconcile_skipped', 'public_commerce_purchase', $pid, $actorForPurchaseEntity, $branchIdForAudit, [
                    'invoice_id' => $invoiceId,
                    'trigger' => $triggerSource,
                    'reason' => self::REASON_PURCHASE_ROW_MISSING,
                ]);
                if ($started) {
                    $pdo->rollBack();
                }

                return array_merge($base, [
                    'outcome' => self::OUTCOME_SKIPPED,
                    'reason' => self::REASON_PURCHASE_ROW_MISSING,
                    'purchase_id' => $pid,
                ]);
            }

            if (in_array((string) ($row['status'] ?? ''), [self::PURCHASE_FAILED, self::PURCHASE_CANCELLED], true)) {
                $this->audit->log('public_commerce_fulfillment_reconcile_blocked', 'public_commerce_purchase', $pid, $actorForPurchaseEntity, $branchIdForAudit, [
                    'invoice_id' => $invoiceId,
                    'trigger' => $triggerSource,
                    'reason' => self::REASON_TERMINAL_PURCHASE,
                    'purchase_status' => (string) ($row['status'] ?? ''),
                ]);
                if ($started) {
                    $pdo->rollBack();
                }

                return array_merge($base, [
                    'outcome' => self::OUTCOME_BLOCKED,
                    'reason' => self::REASON_TERMINAL_PURCHASE,
                    'purchase_id' => $pid,
                ]);
            }

            if (
                (string) ($row['status'] ?? '') === self::PURCHASE_PAID
                && ($row['fulfillment_applied_at'] ?? null) !== null
                && empty($row['fulfillment_reversed_at'])
            ) {
                $this->audit->log('public_commerce_fulfillment_reconcile_skipped', 'public_commerce_purchase', $pid, $actorForPurchaseEntity, $branchIdForAudit, [
                    'invoice_id' => $invoiceId,
                    'trigger' => $triggerSource,
                    'reason' => self::REASON_ALREADY_FULFILLED,
                ]);
                if ($started) {
                    $pdo->commit();
                }

                return array_merge($base, [
                    'outcome' => self::OUTCOME_SKIPPED,
                    'reason' => self::REASON_ALREADY_FULFILLED,
                    'purchase_id' => $pid,
                ]);
            }

            $kind = (string) ($row['product_kind'] ?? '');
            $priorPurchaseStatusForAudit = (string) ($row['status'] ?? '');
            $now = date('Y-m-d H:i:s');

            if ($kind === 'membership') {
                $saleId = (int) ($row['membership_sale_id'] ?? 0);
                $purchaseBranch = (int) ($row['branch_id'] ?? 0);
                $sale = $saleId > 0 && $purchaseBranch > 0
                    ? $this->membershipSales->findInTenantScope($saleId, $purchaseBranch)
                    : null;
                if (!$sale || (string) ($sale['status'] ?? '') !== 'activated') {
                    $this->audit->log('public_commerce_fulfillment_reconcile_still_pending', 'public_commerce_purchase', $pid, $actorForPurchaseEntity, $branchIdForAudit, [
                        'invoice_id' => $invoiceId,
                        'trigger' => $triggerSource,
                        'reason' => self::REASON_MEMBERSHIP_PREREQUISITE,
                        'purchase_status' => $priorPurchaseStatusForAudit,
                        'membership_sale_id' => $saleId > 0 ? $saleId : null,
                        'membership_sale_status' => $sale ? (string) ($sale['status'] ?? '') : null,
                    ]);
                    if ($started) {
                        $pdo->rollBack();
                    }

                    return array_merge($base, [
                        'outcome' => self::OUTCOME_STILL_PENDING,
                        'reason' => self::REASON_MEMBERSHIP_PREREQUISITE,
                        'purchase_id' => $pid,
                        'prior_purchase_status' => $priorPurchaseStatusForAudit,
                    ]);
                }
                $this->purchases->update($pid, [
                    'fulfillment_applied_at' => $now,
                    'fulfillment_reversed_at' => null,
                    'status' => self::PURCHASE_PAID,
                ]);
            } elseif ($kind === 'package') {
                if ((int) ($row['client_package_id'] ?? 0) > 0 && empty($row['fulfillment_reversed_at'])) {
                    $this->purchases->update($pid, [
                        'fulfillment_applied_at' => $now,
                        'status' => self::PURCHASE_PAID,
                    ]);
                } else {
                    $packageId = (int) ($row['package_id'] ?? 0);
                    $clientId = (int) ($row['client_id'] ?? 0);
                    $branch = (int) ($row['branch_id'] ?? 0);
                    $rawSnap = $row['package_snapshot_json'] ?? null;
                    $snapJson = self::jsonColumnToString($rawSnap);
                    $snap = PackageEntitlementSnapshot::decode($snapJson);
                    if (
                        $snap === null
                        || (int) ($snap['package_id'] ?? 0) !== $packageId
                        || (int) ($snap['package_branch_id'] ?? 0) !== $branch
                    ) {
                        $this->audit->log('pc_fulfillment_blocked_missing_snapshot', 'public_commerce_purchase', $pid, $actorForPurchaseEntity, $branchIdForAudit, [
                            'invoice_id' => $invoiceId,
                            'trigger' => $triggerSource,
                            'reason' => PublicCommerceFulfillmentReconcilerContract::REASON_MISSING_PACKAGE_ENTITLEMENT_SNAPSHOT,
                            'package_id' => $packageId,
                        ]);
                        $this->purchases->update($pid, [
                            'status' => self::PURCHASE_FAILED,
                        ]);
                        if ($started) {
                            $pdo->commit();
                        }

                        return array_merge($base, [
                            'outcome' => self::OUTCOME_BLOCKED,
                            'reason' => PublicCommerceFulfillmentReconcilerContract::REASON_MISSING_PACKAGE_ENTITLEMENT_SNAPSHOT,
                            'purchase_id' => $pid,
                        ]);
                    }
                    $sessions = (int) ($snap['total_sessions'] ?? 0);
                    if ($sessions <= 0) {
                        throw new \DomainException('Invalid package sessions.');
                    }
                    $cpId = $this->packageService->assignPackageToClient([
                        'package_id' => $packageId,
                        'client_id' => $clientId,
                        'branch_id' => $branch,
                        'assigned_sessions' => $sessions,
                        'package_entitlement_snapshot' => $snap,
                        'notes' => 'public_commerce_purchase_id=' . $pid,
                    ]);
                    $this->purchases->update($pid, [
                        'client_package_id' => $cpId,
                        'fulfillment_applied_at' => $now,
                        'fulfillment_reversed_at' => null,
                        'status' => self::PURCHASE_PAID,
                    ]);
                }
            } elseif ($kind === 'gift_card') {
                if ((int) ($row['gift_card_id'] ?? 0) > 0 && empty($row['fulfillment_reversed_at'])) {
                    $this->purchases->update($pid, [
                        'fulfillment_applied_at' => $now,
                        'status' => self::PURCHASE_PAID,
                    ]);
                } else {
                    $amt = round((float) ($row['gift_card_amount'] ?? 0), 2);
                    $gcId = $this->giftCardService->issueGiftCard([
                        'branch_id' => (int) ($row['branch_id'] ?? 0),
                        'client_id' => (int) ($row['client_id'] ?? 0),
                        'original_amount' => $amt,
                        'reference_type' => 'public_commerce_purchase',
                        'reference_id' => $pid,
                        'notes' => 'Public online purchase; invoice #' . $invoiceId,
                    ]);
                    $this->purchases->update($pid, [
                        'gift_card_id' => $gcId,
                        'fulfillment_applied_at' => $now,
                        'fulfillment_reversed_at' => null,
                        'status' => self::PURCHASE_PAID,
                    ]);
                }
            }

            $finalCheck = $this->purchases->findCorrelatedToInvoiceRow($inv, $invoiceId);
            if (
                $finalCheck
                && (string) ($finalCheck['status'] ?? '') === self::PURCHASE_PAID
                && !empty($finalCheck['fulfillment_applied_at'])
            ) {
                $this->audit->log('public_commerce_fulfillment_reconcile_applied', 'public_commerce_purchase', (int) ($finalCheck['id'] ?? 0), $actorForPurchaseEntity, (int) ($finalCheck['branch_id'] ?? 0) > 0 ? (int) ($finalCheck['branch_id'] ?? 0) : null, [
                    'invoice_id' => $invoiceId,
                    'trigger' => $triggerSource,
                    'prior_purchase_status' => $priorPurchaseStatusForAudit,
                    'completed_from_awaiting_verification' => $priorPurchaseStatusForAudit === self::PURCHASE_AWAITING_VERIFICATION,
                ]);
            }

            if ($started) {
                $pdo->commit();
            }

            return array_merge($base, [
                'outcome' => self::OUTCOME_APPLIED,
                'reason' => null,
                'purchase_id' => $pid,
                'prior_purchase_status' => $priorPurchaseStatusForAudit,
            ]);
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'public-commerce.fulfillment_reconcile', 'Reconcile raised exception.', [
                'invoice_id' => $invoiceId,
                'exception' => $e->getMessage(),
            ]);

            return array_merge($base, [
                'outcome' => self::OUTCOME_ERROR,
                'reason' => 'exception',
                'purchase_id' => $pid,
                'error_detail' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Refund-side path: when net paid no longer covers invoice total (same rule as invoice “fully paid”),
     * void issued entitlements and stamp {@code fulfillment_reversed_at}. Idempotent.
     */
    private function reconcileAfterPaymentRefund(int $invoiceId, string $triggerSource, ?int $staffActorId): array
    {
        $base = [
            'invoice_id' => $invoiceId,
            'trigger' => $triggerSource,
        ];
        if ($invoiceId <= 0) {
            return array_merge($base, ['outcome' => self::OUTCOME_NOOP_NO_PURCHASE, 'reason' => null]);
        }

        $inv = $this->loadInvoiceRowForPublicCommerceReconcile($invoiceId);
        if ($inv === null) {
            return array_merge($base, [
                'outcome' => self::OUTCOME_SKIPPED,
                'reason' => 'missing_invoice',
                'purchase_id' => null,
            ]);
        }

        $purchase = $this->purchases->findCorrelatedToInvoiceRow($inv, $invoiceId);
        if ($purchase === null) {
            return array_merge($base, ['outcome' => self::OUTCOME_NOOP_NO_PURCHASE, 'reason' => null]);
        }

        $pid = (int) ($purchase['id'] ?? 0);
        $branchIdForAudit = (int) ($purchase['branch_id'] ?? 0) > 0 ? (int) ($purchase['branch_id'] ?? 0) : null;
        $purchaseBranch = (int) ($purchase['branch_id'] ?? 0);

        $this->audit->log('public_commerce_fulfillment_reconcile_requested', 'public_commerce_purchase', $pid, $staffActorId, $branchIdForAudit, [
            'invoice_id' => $invoiceId,
            'trigger' => $triggerSource,
            'public_commerce_purchase_id' => $pid,
            'staff_actor_id' => $staffActorId,
        ]);

        $total = round((float) ($inv['total_amount'] ?? 0), 2);
        $paid = round((float) ($inv['paid_amount'] ?? 0), 2);
        if ($total <= 0) {
            return array_merge($base, [
                'outcome' => self::OUTCOME_SKIPPED,
                'reason' => null,
                'purchase_id' => $pid,
            ]);
        }
        if ($paid >= $total) {
            $this->audit->log('public_commerce_fulfillment_reconcile_skipped', 'public_commerce_purchase', $pid, $staffActorId, $branchIdForAudit, [
                'invoice_id' => $invoiceId,
                'trigger' => $triggerSource,
                'reason' => PublicCommerceFulfillmentReconcilerContract::REASON_INVOICE_STILL_FULLY_PAID,
                'invoice_paid_amount' => $paid,
                'invoice_total_amount' => $total,
            ]);

            return array_merge($base, [
                'outcome' => self::OUTCOME_SKIPPED,
                'reason' => PublicCommerceFulfillmentReconcilerContract::REASON_INVOICE_STILL_FULLY_PAID,
                'purchase_id' => $pid,
            ]);
        }

        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $row = $this->purchases->findForUpdateCorrelatedToInvoiceRow($inv, $invoiceId);
            if ($row === null) {
                if ($started) {
                    $pdo->rollBack();
                }

                return array_merge($base, [
                    'outcome' => self::OUTCOME_SKIPPED,
                    'reason' => self::REASON_PURCHASE_ROW_MISSING,
                    'purchase_id' => $pid,
                ]);
            }

            if (!empty($row['fulfillment_reversed_at'])) {
                if ($started) {
                    $pdo->commit();
                }

                return array_merge($base, [
                    'outcome' => self::OUTCOME_SKIPPED,
                    'reason' => PublicCommerceFulfillmentReconcilerContract::REASON_ALREADY_REVERSED,
                    'purchase_id' => $pid,
                ]);
            }

            if (($row['fulfillment_applied_at'] ?? null) === null || trim((string) ($row['fulfillment_applied_at'] ?? '')) === '') {
                if ($started) {
                    $pdo->commit();
                }

                return array_merge($base, [
                    'outcome' => self::OUTCOME_SKIPPED,
                    'reason' => PublicCommerceFulfillmentReconcilerContract::REASON_FULFILLMENT_NOT_APPLIED,
                    'purchase_id' => $pid,
                ]);
            }

            if (in_array((string) ($row['status'] ?? ''), [self::PURCHASE_FAILED, self::PURCHASE_CANCELLED], true)) {
                if ($started) {
                    $pdo->rollBack();
                }

                return array_merge($base, [
                    'outcome' => self::OUTCOME_BLOCKED,
                    'reason' => self::REASON_TERMINAL_PURCHASE,
                    'purchase_id' => $pid,
                ]);
            }

            $kind = (string) ($row['product_kind'] ?? '');
            $now = date('Y-m-d H:i:s');
            $notes = 'Public commerce fulfillment reversal (invoice #' . $invoiceId . ', refund reconciliation)';

            if ($purchaseBranch <= 0) {
                throw new \DomainException('Public commerce purchase branch is required for fulfillment reversal.');
            }

            if ($kind === 'gift_card') {
                $gcId = (int) ($row['gift_card_id'] ?? 0);
                if ($gcId > 0) {
                    $this->giftCardService->cancelGiftCard($gcId, $notes, $purchaseBranch);
                }
            } elseif ($kind === 'package') {
                $cpId = (int) ($row['client_package_id'] ?? 0);
                if ($cpId > 0) {
                    $this->packageService->cancelClientPackage($cpId, $notes, $purchaseBranch);
                }
            } elseif ($kind === 'membership') {
                $saleId = (int) ($row['membership_sale_id'] ?? 0);
                if ($saleId > 0) {
                    $sale = $this->membershipSales->findInTenantScope($saleId, $purchaseBranch);
                    if ($sale !== null) {
                        $cmId = (int) ($sale['client_membership_id'] ?? 0);
                        if ($cmId > 0) {
                            $this->membershipService->cancelClientMembership($cmId, $notes);
                        }
                    }
                }
            }

            $this->purchases->update($pid, [
                'fulfillment_reversed_at' => $now,
            ]);

            $this->audit->log('public_commerce_fulfillment_reversed', 'public_commerce_purchase', $pid, $staffActorId, $branchIdForAudit, [
                'invoice_id' => $invoiceId,
                'trigger' => $triggerSource,
                'product_kind' => $kind,
            ]);

            if ($started) {
                $pdo->commit();
            }

            return array_merge($base, [
                'outcome' => PublicCommerceFulfillmentReconcilerContract::OUTCOME_REVERSED,
                'reason' => null,
                'purchase_id' => $pid,
            ]);
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'public-commerce.fulfillment_reconcile_refund', 'Refund-side reconcile raised exception.', [
                'invoice_id' => $invoiceId,
                'exception' => $e->getMessage(),
            ]);

            return array_merge($base, [
                'outcome' => self::OUTCOME_ERROR,
                'reason' => 'exception',
                'purchase_id' => $pid,
                'error_detail' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Prefer tenant-scoped {@see InvoiceRepository::find}; on missing branch-derived org (anonymous public), correlate via
     * public-commerce purchase branch pin + {@see InvoiceRepository::findForPublicCommerceCorrelatedBranch}.
     * Does **not** run fallback when {@see InvoiceRepository::find} returns null without throwing (wrong-tenant filter).
     *
     * @return array<string, mixed>|null
     */
    private function loadInvoiceRowForPublicCommerceReconcile(int $invoiceId): ?array
    {
        try {
            return $this->invoiceRepo->find($invoiceId);
        } catch (AccessDeniedException) {
            $pin = $this->purchases->findBranchIdPinByInvoiceId($invoiceId);
            if ($pin === null) {
                return null;
            }

            return $this->invoiceRepo->findForPublicCommerceCorrelatedBranch($invoiceId, $pin);
        }
    }

    /**
     * @param mixed $value PDO JSON column (string|array) or null
     */
    private static function jsonColumnToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        return null;
    }
}
