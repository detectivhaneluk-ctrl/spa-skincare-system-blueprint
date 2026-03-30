<?php

declare(strict_types=1);

/**
 * Read-only proof: Sales entry = cashier workspace; Manage Sales = orders list; shared workspace shell.
 *
 * Usage (from system/):
 *   php scripts/read-only/proof_sales_index_to_cashier_visibility_fix_01.php
 */

$base = dirname(__DIR__, 2);

function mustContain(string $file, string $needle): bool
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';

    return $text !== '' && str_contains($text, $needle);
}

$checks = [];

$routes = $base . '/routes/web/register_sales_public_commerce_staff.php';
$salesController = $base . '/modules/sales/controllers/SalesController.php';
$salesIndex = $base . '/modules/sales/views/index.php';
$invoiceIndex = $base . '/modules/sales/views/invoices/index.php';
$salesShell = $base . '/modules/sales/views/partials/sales-workspace-shell.php';
$cashierWorkspace = $base . '/modules/sales/views/invoices/_cashier_workspace.php';

$checks['sales_route_exists'] = mustContain($routes, "get('/sales', [\\Modules\\Sales\\Controllers\\SalesController::class, 'index']");
$checks['sales_controller_delegates_to_invoice'] = mustContain($salesController, 'staffCheckoutFromSalesRoute')
    && mustContain($salesController, 'InvoiceController')
    && !mustContain($salesController, "header('Location: /sales/invoices'");
$checks['legacy_sales_index_view_removed'] = !is_file($salesIndex);
$checks['workspace_shell_sales_ia'] = mustContain($salesShell, "'label' => 'Manage Sales'")
    && mustContain($salesShell, "'id' => 'staff_checkout'")
    && mustContain($salesShell, "'url' => '/sales/invoices'")
    && mustContain($salesShell, "'url' => '/gift-cards'")
    && mustContain($salesShell, "'url' => '/packages'")
    && mustContain($salesShell, "'url' => '/sales/register'");
$cashierText = is_file($cashierWorkspace) ? (string) file_get_contents($cashierWorkspace) : '';
$checks['no_misleading_caisse_subnav_to_create'] = $cashierText === '' || !str_contains($cashierText, 'href="/sales/invoices/create">Caisse');

$checks['invoice_index_includes_workspace_shell'] = mustContain($invoiceIndex, 'sales-workspace-shell.php')
    && mustContain($invoiceIndex, "salesWorkspaceActiveTab = 'manage_sales'");
$checks['register_route_registered'] = mustContain($routes, "get('/sales/register'");
$checks['invoice_index_orders_surface'] = mustContain($invoiceIndex, 'Sales orders')
    && mustContain($invoiceIndex, '>New sale</a>');
$checks['cashier_create_edit_routes_still_registered'] = mustContain($routes, "get('/sales/invoices/create'")
    && mustContain($routes, "get('/sales/invoices/{id}/edit'");

$allPass = !in_array(false, $checks, true);

echo "=== sales_index_to_cashier_visibility_fix_01 (current) ===\n";
foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo 'overall=' . ($allPass ? 'PASS' : 'FAIL') . "\n";
exit($allPass ? 0 : 1);
