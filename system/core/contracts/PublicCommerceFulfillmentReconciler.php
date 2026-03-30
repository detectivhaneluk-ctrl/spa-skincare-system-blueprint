<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Single authoritative internal entry for re-evaluating public-commerce fulfillment for an invoice.
 * All trusted lifecycle paths (invoice/payment settlement, membership prerequisite, staff retry, public finalize)
 * must call {@see reconcile} with a proven trigger source — not ad-hoc fulfillment logic elsewhere.
 */
interface PublicCommerceFulfillmentReconciler
{
    public const TRIGGER_INVOICE_SETTLEMENT = 'invoice_settlement';

    public const TRIGGER_MEMBERSHIP_PREREQUISITE_COMPLETE = 'membership_prerequisite_complete';

    public const TRIGGER_STAFF_MANUAL_SYNC = 'staff_manual_sync';

    public const TRIGGER_PUBLIC_FINALIZE_TRUSTED_INVOICE = 'public_finalize_trusted_invoice';

    /** Trusted batch/CLI repair: re-runs the same reconcile rules without inventing payment truth. */
    public const TRIGGER_INTERNAL_REPAIR_BATCH = 'internal_repair_batch';

    /** After {@see \Modules\Sales\Services\PaymentService::refund} + invoice recompute; reverses fulfillment when invoice is no longer fully paid. */
    public const TRIGGER_PAYMENT_REFUND = 'payment_refund';

    public const OUTCOME_NOOP_NO_PURCHASE = 'noop_no_purchase';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_BLOCKED = 'blocked';

    public const OUTCOME_STILL_PENDING = 'still_pending';

    public const OUTCOME_APPLIED = 'applied';

    /** Fulfillment entitlements were voided after refund-side reconciliation (idempotent if already reversed). */
    public const OUTCOME_REVERSED = 'reversed';

    public const OUTCOME_ERROR = 'error';

    public const REASON_INVOICE_NOT_PAID = 'invoice_not_paid';

    /** Refund trigger only: {@see PaymentRepository}-consistent net paid still covers invoice total. */
    public const REASON_INVOICE_STILL_FULLY_PAID = 'invoice_still_fully_paid';

    public const REASON_ALREADY_FULFILLED = 'already_fulfilled';

    public const REASON_ALREADY_REVERSED = 'fulfillment_already_reversed';

    public const REASON_FULFILLMENT_NOT_APPLIED = 'fulfillment_not_applied';

    public const REASON_TERMINAL_PURCHASE = 'terminal_purchase';

    public const REASON_MEMBERSHIP_PREREQUISITE = 'membership_sale_not_activated';

    public const REASON_PURCHASE_ROW_MISSING = 'purchase_row_missing';

    /** Paid package purchase row lacks required sell-time snapshot; fulfillment must not use mutable package definition. */
    public const REASON_MISSING_PACKAGE_ENTITLEMENT_SNAPSHOT = 'missing_package_entitlement_snapshot';

    /**
     * @return array{outcome: string, reason?: string|null, purchase_id?: int|null, prior_purchase_status?: string|null, invoice_id: int, trigger: string, error_detail?: string|null}
     *             Outcome {@see OUTCOME_ERROR} when an unexpected exception interrupted reconciliation.
     *             Outcome {@see OUTCOME_REVERSED} when refund-side reconciliation voided prior fulfillment.
     */
    public function reconcile(int $invoiceId, string $triggerSource, ?int $staffActorId = null): array;
}
