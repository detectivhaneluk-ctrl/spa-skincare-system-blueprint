<?php

declare(strict_types=1);

/**
 * SALES-TENANT-MUTATION-GUARD-HARDENING-01 — static proof: Sales invoice/payment mutation entry points
 * align with branch-safe patterns (controller pre-checks where applicable + service-layer asserts).
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_sales_invoice_payment_tenant_mutation_guard_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$routes = (string) file_get_contents($system . '/routes/web/register_sales_public_commerce_staff.php');
$invCtl = (string) file_get_contents($system . '/modules/sales/controllers/InvoiceController.php');
$payCtl = (string) file_get_contents($system . '/modules/sales/controllers/PaymentController.php');
$invSvc = (string) file_get_contents($system . '/modules/sales/services/InvoiceService.php');
$paySvc = (string) file_get_contents($system . '/modules/sales/services/PaymentService.php');
$pubSvc = (string) file_get_contents($system . '/modules/public-commerce/services/PublicCommerceService.php');

$checks = [];

$checks['Routes: POST invoice update/cancel/delete/redeem-gift-card registered'] = str_contains($routes, "post('/sales/invoices/{id}', ")
    && str_contains($routes, "post('/sales/invoices/{id}/cancel', ")
    && str_contains($routes, "post('/sales/invoices/{id}/delete', ")
    && str_contains($routes, "post('/sales/invoices/{id}/redeem-gift-card', ");
$checks['Routes: POST payment store + refund registered'] = str_contains($routes, "post('/sales/invoices/{id}/payments', ")
    && str_contains($routes, "post('/sales/payments/{id:\\d+}/refund', ");

// Typed signatures: `function name(int $id): void` (PHP 8); pre-sequence: protected tenant scope → repo find → 404 path → branch gate → service.
$invMutFn = static function (string $name, string $serviceCall): string {
    return '/function\s+' . preg_quote($name, '/') . '\s*\(\s*int\s+\$id\s*\)\s*:\s*void[\s\S]*?ensureProtectedTenantScope\(\)[\s\S]*?'
        . '\$invoice\s*=\s*\$this->repo->find\(\$id\)[\s\S]*?ensureBranchAccess\(\$invoice\)[\s\S]*?' . $serviceCall . '/';
};

$checks['InvoiceController::destroy pre-checks tenant scope, find, branch before delete'] = preg_match(
    $invMutFn('destroy', preg_quote('$this->service->delete($id)', '/')),
    $invCtl
) === 1;

$checks['InvoiceController::update pre-checks tenant scope, find, branch before service'] = preg_match(
    $invMutFn('update', preg_quote('$this->service->update($id', '/')),
    $invCtl
) === 1;

$checks['InvoiceController::cancel pre-checks tenant scope, find, branch before service'] = preg_match(
    $invMutFn('cancel', preg_quote('$this->service->cancel($id)', '/')),
    $invCtl
) === 1;

$checks['InvoiceController::redeemGiftCard pre-checks tenant scope, find, branch before service'] = preg_match(
    $invMutFn('redeemGiftCard', 'redeemGiftCardPayment\(\$id'),
    $invCtl
) === 1;

$checks['PaymentController::store pre-checks tenant scope, find, branch before create'] = preg_match(
    '/function\s+store\s*\(\s*int\s+\$invoiceId\s*\)\s*:\s*void[\s\S]*?ensureProtectedTenantScope\(\)[\s\S]*?'
    . '\$invoice\s*=\s*\$this->invoiceRepo->find\(\$invoiceId\)[\s\S]*?ensureBranchAccessForInvoice\(\$invoice\)[\s\S]*?'
    . '\$this->service->create\(\$data\)/',
    $payCtl
) === 1;

$checks['PaymentController::refund pre-checks tenant scope, payment find, invoice resolve, branch before refund'] = preg_match(
    '/function\s+refund\s*\(\s*int\s+\$id\s*\)\s*:\s*void[\s\S]*?ensureProtectedTenantScope\(\)[\s\S]*?'
    . '\$payment\s*=\s*\$this->paymentRepo->findInInvoicePlane\(\$id\)[\s\S]*?'
    . '\$invoice\s*=\s*\$invoiceId\s*>\s*0\s*\?\s*\$this->invoiceRepo->find\(\$invoiceId\)\s*:\s*null[\s\S]*?'
    . 'ensureBranchAccessForInvoice\(\$invoice\)[\s\S]*?\$this->service->refund\(\$id/',
    $payCtl
) === 1;

$checks['InvoiceService::delete asserts branch + org on loaded invoice'] = preg_match(
    '/function delete\(int \$id\)[\s\S]*?assertBranchMatchOrGlobalEntity[\s\S]*?assertBranchOwnedByResolvedOrganization/',
    $invSvc
) === 1;

$checks['InvoiceService::update asserts branch + org after findForUpdate'] = preg_match(
    '/function update\(int \$id[\s\S]*?findForUpdate\(\$id\)[\s\S]*?assertBranchMatchOrGlobalEntity[\s\S]*?assertBranchOwnedByResolvedOrganization/',
    $invSvc
) === 1;

$checks['InvoiceService::cancel asserts branch + org'] = preg_match(
    '/function cancel\(int \$id\)[\s\S]*?assertBranchMatchOrGlobalEntity[\s\S]*?assertBranchOwnedByResolvedOrganization/',
    $invSvc
) === 1;

$checks['InvoiceService::redeemGiftCardPayment asserts branch + org'] = preg_match(
    '/function redeemGiftCardPayment\([\s\S]*?findForUpdate\(\$invoiceId\)[\s\S]*?assertBranchMatchOrGlobalEntity[\s\S]*?assertBranchOwnedByResolvedOrganization/',
    $invSvc
) === 1;

$checks['InvoiceService::create enforces branch on create payload'] = str_contains($invSvc, 'function create(')
    && str_contains($invSvc, 'enforceBranchOnCreate')
    && str_contains($invSvc, 'assertBranchOwnedByResolvedOrganization');

$checks['PaymentService::create asserts branch after invoice findForUpdate'] = preg_match(
    '/function create\(array \$data\)[\s\S]*?findForUpdate\(\$invoiceId\)[\s\S]*?assertBranchMatchOrGlobalEntity[\s\S]*?assertBranchOwnedByResolvedOrganization/',
    $paySvc
) === 1;

$checks['PaymentService::refund asserts branch after invoice findForUpdate'] = preg_match(
    '/function refund\(int \$paymentId[\s\S]*?findForUpdate\(\$invoiceId\)[\s\S]*?assertBranchMatchOrGlobalEntity[\s\S]*?assertBranchOwnedByResolvedOrganization/',
    $paySvc
) === 1;

$checks['PublicCommerce staffTrustedFulfillmentSync branch assert after tenant-scoped invoice find'] = preg_match(
    '/function\s+staffTrustedFulfillmentSync\s*\(\s*int\s+\$invoiceId\s*\)[\s\S]*?'
    . '\$inv\s*=\s*\$this->invoiceRepo->find\(\$invoiceId\)[\s\S]*?'
    . 'branchContext->assertBranchMatchOrGlobalEntity/',
    $pubSvc
) === 1;

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'All Sales invoice/payment mutation guard static checks passed.' . PHP_EOL;
exit(0);
