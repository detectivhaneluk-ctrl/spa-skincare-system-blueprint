<?php

declare(strict_types=1);

/**
 * C-006-PUBLIC-COMMERCE-REFUND-FULFILLMENT-REVERSAL-01: static proof for refund-side fulfillment reversal
 * (contract constant, PaymentService hook, reconciler branch, schema/migration). No database.
 *
 * Usage:
 *   php system/scripts/read-only/verify_public_commerce_refund_fulfillment_reversal_c006_01.php
 */

$base = dirname(__DIR__, 2);
$paths = [
    'contract' => $base . '/core/contracts/PublicCommerceFulfillmentReconciler.php',
    'payment' => $base . '/modules/sales/services/PaymentService.php',
    'reconciler' => $base . '/modules/public-commerce/services/PublicCommerceFulfillmentReconciler.php',
    'repo' => $base . '/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php',
    'migration' => $base . '/data/migrations/109_public_commerce_fulfillment_reversed_at.sql',
    'schema' => $base . '/data/full_project_schema.sql',
];

foreach ($paths as $label => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: missing {$label}: {$path}\n");
        exit(1);
    }
}

$c = (string) file_get_contents($paths['contract']);
$p = (string) file_get_contents($paths['payment']);
$r = (string) file_get_contents($paths['reconciler']);
$repo = (string) file_get_contents($paths['repo']);
$m = (string) file_get_contents($paths['migration']);
$s = (string) file_get_contents($paths['schema']);

$refundPos = strpos($p, 'public function refund(');
$afterRefund = $refundPos !== false ? strpos($p, "\n    private function invoiceCurrencyForAudit", $refundPos) : false;
$refundFn = ($refundPos !== false && $afterRefund !== false) ? substr($p, $refundPos, $afterRefund - $refundPos) : '';

$checks = [
    'contract: TRIGGER_PAYMENT_REFUND + OUTCOME_REVERSED' => str_contains($c, 'TRIGGER_PAYMENT_REFUND')
        && str_contains($c, 'OUTCOME_REVERSED'),
    'PaymentService::refund calls reconcile(TRIGGER_PAYMENT_REFUND)' => $refundFn !== ''
        && str_contains($refundFn, 'TRIGGER_PAYMENT_REFUND')
        && str_contains($refundFn, 'reconcile('),
    'PublicCommerceFulfillmentReconciler dispatches refund trigger first' => str_contains($r, 'TRIGGER_PAYMENT_REFUND')
        && str_contains($r, 'reconcileAfterPaymentRefund'),
    'reconciler stamps fulfillment_reversed_at on reversal' => str_contains($r, "'fulfillment_reversed_at'")
        && str_contains($r, 'public_commerce_fulfillment_reversed'),
    'forward path respects fulfillment_reversed_at for package/gift fast path' => str_contains($r, 'empty($row[\'fulfillment_reversed_at\'])'),
    'repository allows fulfillment_reversed_at in update' => str_contains($repo, "'fulfillment_reversed_at'"),
    'migration 109 adds fulfillment_reversed_at' => str_contains($m, 'fulfillment_reversed_at'),
    'full_project_schema includes fulfillment_reversed_at on public_commerce_purchases' => preg_match(
        '/CREATE TABLE public_commerce_purchases\s*\([^;]*fulfillment_reversed_at/s',
        $s
    ) === 1,
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'MISSING') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
