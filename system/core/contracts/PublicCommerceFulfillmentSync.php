<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Thin adapter for sales/payment modules: delegates to {@see PublicCommerceFulfillmentReconciler}.
 * Prefer injecting the reconciler directly in new code; this contract remains for existing DI bindings.
 */
interface PublicCommerceFulfillmentSync
{
    public function syncFulfillmentForInvoice(int $invoiceId, ?string $syncSource = null): void;
}
