<?php

declare(strict_types=1);

/**
 * H-001 fail-closed: recovery flag gates public status/finalize; post-reconcile outcome recording is centralized.
 * No database.
 */
$base = dirname(__DIR__, 2);
$svc = (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceService.php');
$rec = (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceFulfillmentReconcileRecoveryService.php');
$repair = (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceFulfillmentRepairService.php');
$mem = (string) file_get_contents($base . '/modules/memberships/Services/MembershipSaleService.php');

$checks = [
    'RecoveryService exposes recordPostReconcileOutcome' => str_contains($rec, 'function recordPostReconcileOutcome'),
    'RecoveryService narrows clear to applied/reversed/already_fulfilled' => str_contains($rec, 'shouldClearRecoveryAfterReconcile')
        && str_contains($rec, 'OUTCOME_APPLIED')
        && str_contains($rec, 'REASON_ALREADY_FULFILLED'),
    'RecoveryService persists throwable for external callers' => str_contains($rec, 'function persistRecoveryAfterReconcileThrowable'),
    'PublicCommerce finalize records outcome + handles OUTCOME_ERROR' => str_contains($svc, 'recordPostReconcileOutcome')
        && str_contains($svc, 'buildRecoveryPendingFinalizeResponse')
        && str_contains($svc, 'OUTCOME_ERROR'),
    'PublicCommerce status exposes recovery pending + checkout_state guard' => str_contains($svc, "'fulfillment_reconcile_recovery_pending'")
        && str_contains($svc, 'fulfillment_reconcile_recovery_pending'),
    'resolveCheckoutState prefers recovery pending over complete' => str_contains($svc, 'isFulfillmentReconcileRecoveryPending($purchase)')
        && preg_match('/function resolveCheckoutState[\s\S]*?isFulfillmentReconcileRecoveryPending/m', $svc) === 1,
    'fulfillmentSummary blocks receipt_safe when recovery pending' => str_contains($svc, 'operator_repair_required'),
    'Repair batch uses recordPostReconcileOutcome not blind clear' => str_contains($repair, 'recordPostReconcileOutcome')
        && !str_contains($repair, "if (\$outcome !== PublicCommerceFulfillmentReconcilerContract::OUTCOME_ERROR && \$pid > 0)"),
    'Membership activation reconcile records recovery outcome' => str_contains($mem, 'recordPostReconcileOutcome')
        && str_contains($mem, 'persistRecoveryAfterReconcileThrowable'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
