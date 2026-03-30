<?php

declare(strict_types=1);

/**
 * FND-TNT-05 — Static proof: public_commerce_purchases invoice lookups are correlated to tenant invoice rows
 * (branch predicate and/or live-invoice join), not id-only on invoice_id.
 *
 * Run: php system/scripts/read-only/verify_public_commerce_purchase_invoice_correlation_fnd_tnt_05.php
 */

$base = dirname(__DIR__, 2);
$repo = (string) file_get_contents($base . '/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php');
$rec = (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceFulfillmentReconciler.php');
$svc = (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceService.php');
$recovery = (string) file_get_contents($base . '/modules/public-commerce/services/PublicCommerceFulfillmentReconcileRecoveryService.php');

$ok = true;
$fail = static function (string $m) use (&$ok): void {
    fwrite(STDERR, "FAIL: {$m}\n");
    $ok = false;
};

if ($repo === '') {
    $fail('missing PublicCommercePurchaseRepository.php');
}
if (preg_match('/public function findByInvoiceId\s*\(\s*int/', $repo)) {
    $fail('unsafe id-only findByInvoiceId(int) must be removed');
}
if (preg_match('/public function findForUpdateByInvoiceId\s*\(\s*int/', $repo)) {
    $fail('unsafe id-only findForUpdateByInvoiceId(int) must be removed');
}
foreach (['findByInvoiceIdForBranch', 'findByInvoiceIdAttachedToLiveInvoice', 'findCorrelatedToInvoiceRow', 'findForUpdateByInvoiceIdForBranch', 'findForUpdateByInvoiceIdAttachedToLiveInvoice', 'findForUpdateCorrelatedToInvoiceRow'] as $needle) {
    if (!str_contains($repo, 'function ' . $needle)) {
        $fail('PublicCommercePurchaseRepository missing ' . $needle);
    }
}
if (!str_contains($repo, 'invoice_id = ? AND branch_id = ?')) {
    $fail('branch-scoped purchase SQL must constrain invoice_id + branch_id');
}
if (!str_contains($repo, 'INNER JOIN invoices i ON i.id = p.invoice_id')) {
    $fail('live-invoice join path must INNER JOIN invoices');
}

foreach (['findCorrelatedToInvoiceRow', 'findForUpdateCorrelatedToInvoiceRow'] as $needle) {
    if (substr_count($rec, '->' . $needle) < 1) {
        $fail('PublicCommerceFulfillmentReconciler must call purchases->' . $needle);
    }
}
if (!str_contains($svc, 'findCorrelatedToInvoiceRow')) {
    $fail('PublicCommerceService must use findCorrelatedToInvoiceRow');
}
if (!str_contains($recovery, 'InvoiceRepository') || !str_contains($recovery, 'invoiceRepo->find')) {
    $fail('PublicCommerceFulfillmentReconcileRecoveryService must use InvoiceRepository::find before purchase correlation');
}
if (!str_contains($recovery, 'findCorrelatedToInvoiceRow')) {
    $fail('Recovery service must use findCorrelatedToInvoiceRow');
}

$boot = (string) file_get_contents($base . '/modules/bootstrap/register_sales_public_commerce_memberships_settings.php');
if (!str_contains($boot, 'PublicCommerceFulfillmentReconcileRecoveryService::class') || !preg_match('/PublicCommerceFulfillmentReconcileRecoveryService::class[\s\S]*?InvoiceRepository::class/s', $boot)) {
    $fail('bootstrap must wire InvoiceRepository into PublicCommerceFulfillmentReconcileRecoveryService');
}

if (!$ok) {
    exit(1);
}

echo "PASS: verify_public_commerce_purchase_invoice_correlation_fnd_tnt_05\n";
exit(0);
