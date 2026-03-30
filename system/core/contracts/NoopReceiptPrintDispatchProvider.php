<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Default receipt dispatch: no device, no queue, no success audit. Extensibility anchor only.
 */
final class NoopReceiptPrintDispatchProvider implements ReceiptPrintDispatchProvider
{
    public function dispatchAfterPaymentRecorded(int $invoiceId, int $paymentId, ?int $branchId): void
    {
    }
}
