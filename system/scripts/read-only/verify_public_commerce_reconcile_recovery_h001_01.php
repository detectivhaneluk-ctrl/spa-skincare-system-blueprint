<?php

declare(strict_types=1);

/**
 * H-001: static proof that sales hooks persist reconcile failure state (not log-only swallow).
 * No database.
 */
$base = dirname(__DIR__, 2);
$checks = [
    'PaymentService uses PublicCommerceFulfillmentReconcileRecoveryService' => str_contains(
        (string) file_get_contents($base . '/modules/sales/services/PaymentService.php'),
        'publicCommerceFulfillmentReconcileRecovery->reconcileAndPersistRecoveryIfFailed'
    ),
    'PaymentService has no reconcile try/catch error_log swallow' => !str_contains(
        (string) file_get_contents($base . '/modules/sales/services/PaymentService.php'),
        '[public-commerce] reconcile after payment invoice'
    ),
    'InvoiceService uses recovery service (update + gift card)' => substr_count(
        (string) file_get_contents($base . '/modules/sales/services/InvoiceService.php'),
        'publicCommerceFulfillmentReconcileRecovery->reconcileAndPersistRecoveryIfFailed'
    ) >= 2,
    'InvoiceService has no reconcile-after-update error_log swallow' => !str_contains(
        (string) file_get_contents($base . '/modules/sales/services/InvoiceService.php'),
        '[public-commerce] reconcile after invoice update'
    ),
    'Recovery service persists on OUTCOME_ERROR' => str_contains(
        (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceFulfillmentReconcileRecoveryService.php'),
        'OUTCOME_ERROR'
    ) && str_contains(
        (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceFulfillmentReconcileRecoveryService.php'),
        'setFulfillmentReconcileRecovery'
    ),
    'Recovery service audits pending' => str_contains(
        (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceFulfillmentReconcileRecoveryService.php'),
        'public_commerce_fulfillment_reconcile_recovery_pending'
    ),
    'Repository exposes recovery columns + set/clear' => str_contains(
        (string) file_get_contents($base . '/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php'),
        'setFulfillmentReconcileRecovery'
    ) && str_contains(
        (string) file_get_contents($base . '/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php'),
        'fulfillment_reconcile_recovery_at'
    ),
    'Repair list includes recovery-flagged purchases' => str_contains(
        (string) file_get_contents($base . '/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php'),
        'fulfillment_reconcile_recovery_at IS NOT NULL'
    ),
    'Repair batch clears recovery after non-error reconcile' => str_contains(
        (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceFulfillmentRepairService.php'),
        'clearFulfillmentReconcileRecovery'
    ),
    'Reconciler error returns include error_detail' => substr_count(
        (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceFulfillmentReconciler.php'),
        "'error_detail' => \$e->getMessage()"
    ) >= 2,
    'Migration 110 adds recovery columns' => str_contains(
        (string) file_get_contents($base . '/data/migrations/110_public_commerce_fulfillment_reconcile_recovery_h001.sql'),
        'fulfillment_reconcile_recovery_at'
    ),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
