<?php

declare(strict_types=1);

/**
 * SALES-TENANT-READ-GUARD-HARDENING-01 — static proof: staff Sales invoice/payment id paths use
 * org-scoped repository SQL + session branch gate where applicable.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_sales_invoice_payment_tenant_read_guard_readonly_01.php
 *
 * Exit: 0 = all checks passed, 1 = failure.
 */

$system = dirname(__DIR__, 2);
$checks = [];

$invRepo = (string) file_get_contents($system . '/modules/sales/repositories/InvoiceRepository.php');
$payRepo = (string) file_get_contents($system . '/modules/sales/repositories/PaymentRepository.php');
$invCtl = (string) file_get_contents($system . '/modules/sales/controllers/InvoiceController.php');
$payCtl = (string) file_get_contents($system . '/modules/sales/controllers/PaymentController.php');
$routes = (string) file_get_contents($system . '/routes/web/register_sales_public_commerce_staff.php');
$pubSvc = (string) file_get_contents($system . '/modules/public-commerce/services/PublicCommerceService.php');
$bootstrap = (string) file_get_contents($system . '/modules/bootstrap/register_sales_public_commerce_memberships_settings.php');

$checks['InvoiceRepository::find uses tenant invoiceClause'] = str_contains($invRepo, 'tenantScope->invoiceClause')
    && str_contains($invRepo, 'function find(');
$checks['InvoiceRepository::findForUpdate uses tenant invoiceClause'] = str_contains($invRepo, 'function findForUpdate')
    && preg_match('/findForUpdate[\s\S]*tenantScope->invoiceClause/', $invRepo) === 1;
$checks['PaymentRepository explicit invoice-plane read methods use guard + paymentByInvoiceExistsClause'] =
    str_contains($payRepo, 'function findInInvoicePlane(')
    && str_contains($payRepo, 'function findForUpdateInInvoicePlane(')
    && str_contains($payRepo, 'denyMixedSemanticsApi(\'PaymentRepository::find\'')
    && str_contains($payRepo, 'denyMixedSemanticsApi(\'PaymentRepository::findForUpdate\'')
    && str_contains($payRepo, 'paymentByInvoiceExistsClause');
$checks['InvoiceController::show gates branch after find'] = str_contains($invCtl, 'function show(')
    && preg_match('/function show\([\s\S]*?\$this->repo->find\(\$id\)[\s\S]*?ensureBranchAccess\(\$invoice\)/', $invCtl) === 1;
$checks['InvoiceController::edit gates branch after find'] = str_contains($invCtl, 'function edit(')
    && preg_match('/function edit\([\s\S]*?\$this->repo->find\(\$id\)[\s\S]*?ensureBranchAccess\(\$invoice\)/', $invCtl) === 1;
$checks['PaymentController has ensureBranchAccessForInvoice'] = str_contains($payCtl, 'function ensureBranchAccessForInvoice');
$checks['PaymentController::create gates branch after invoice find'] = preg_match(
    '/function create\([\s\S]*?invoiceRepo->find\(\$invoiceId\)[\s\S]*?ensureBranchAccessForInvoice\(\$invoice\)/',
    $payCtl
) === 1;
$checks['PaymentController::store gates branch after invoice find'] = preg_match(
    '/function store\([\s\S]*?invoiceRepo->find\(\$invoiceId\)[\s\S]*?ensureBranchAccessForInvoice\(\$invoice\)/',
    $payCtl
) === 1;
$checks['PaymentController::refund loads invoice and gates branch'] = preg_match(
    '/function refund\([\s\S]*?paymentRepo->findInInvoicePlane\(\$id\)[\s\S]*?invoiceRepo->find\(\$invoiceId\)[\s\S]*?ensureBranchAccessForInvoice\(\$invoice\)/',
    $payCtl
) === 1;
$checks['Routes: GET invoice show + payment create registered'] = str_contains($routes, "get('/sales/invoices/{id}', [\\Modules\\Sales\\Controllers\\InvoiceController::class, 'show']")
    && str_contains($routes, "get('/sales/invoices/{id}/payments/create', [\\Modules\\Sales\\Controllers\\PaymentController::class, 'create']");
$checks['PublicCommerceService::staffTrustedFulfillmentSync asserts branch after invoice find'] = preg_match(
    '/function staffTrustedFulfillmentSync\([\s\S]*?invoiceRepo->find\(\$invoiceId\)[\s\S]*?branchContext->assertBranchMatchOrGlobalEntity/',
    $pubSvc
) === 1;
$payBootstrapLine = '';
foreach (explode("\n", $bootstrap) as $line) {
    if (str_contains($line, 'PaymentController::class') && str_contains($line, 'new \\Modules\\Sales\\Controllers\\PaymentController')) {
        $payBootstrapLine = $line;
        break;
    }
}
$checks['Bootstrap: PaymentController line includes BranchContext'] = str_contains($payBootstrapLine, '\\Core\\Branch\\BranchContext::class');

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

echo PHP_EOL . 'All SALES tenant read-guard static checks passed.' . PHP_EOL;
exit(0);
