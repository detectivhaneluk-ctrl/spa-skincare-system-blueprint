<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Receipt hardware hook after canonical payment rows are persisted.
 * Default implementation is a safe no-op; deployments replace the binding to drive a real device/queue.
 * Implementations must not break payment flows — swallow or log internally; callers also wrap in try/catch.
 */
interface ReceiptPrintDispatchProvider
{
    /**
     * Invoked after a completed payment is committed when {@see \Core\App\SettingsService::isReceiptPrintingEnabled} is true.
     * Does not imply a physical print occurred.
     */
    public function dispatchAfterPaymentRecorded(int $invoiceId, int $paymentId, ?int $branchId): void;
}
