<?php

declare(strict_types=1);

/**
 * Read-only proof: Sales IA — cashier default landing + workspace subnav shell.
 *
 * GET /sales renders staff checkout (cashier workspace). Persistent subnav lives in sales-workspace-shell.php (Appointments-style workspace classes).
 *
 * Usage (from system/):
 *   php scripts/read-only/proof_sales_index_booker_style_home_surface_01.php
 */

$base = dirname(__DIR__, 2);

function mustContain(string $file, string $needle): bool
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';

    return $text !== '' && str_contains($text, $needle);
}

$checks = [];

$routes = $base . '/routes/web/register_sales_public_commerce_staff.php';
$controller = $base . '/modules/sales/controllers/SalesController.php';
$salesIndex = $base . '/modules/sales/views/index.php';
$salesShell = $base . '/modules/sales/views/partials/sales-workspace-shell.php';
$cashierWorkspace = $base . '/modules/sales/views/invoices/_cashier_workspace.php';
$cashierCreate = $base . '/modules/sales/views/invoices/create.php';
$cashierEdit = $base . '/modules/sales/views/invoices/edit.php';

$checks['sales_route_wired'] = mustContain($routes, "get('/sales', [\\Modules\\Sales\\Controllers\\SalesController::class, 'index']");
$checks['sales_controller_delegates_not_redirect'] = mustContain($controller, 'staffCheckoutFromSalesRoute')
    && mustContain($controller, 'InvoiceController')
    && !mustContain($controller, "header('Location: /sales/invoices'");
$checks['cashier_create_requires_shell_and_partial'] = mustContain($cashierCreate, "sales-workspace-shell.php")
    && mustContain($cashierCreate, "require __DIR__ . '/_cashier_workspace.php'");
$checks['workspace_shell_has_sales_tabs'] = mustContain($salesShell, 'Manage Sales')
    && mustContain($salesShell, 'New sale')
    && mustContain($salesShell, "'id' => 'staff_checkout'")
    && mustContain($salesShell, 'Gift cards')
    && mustContain($salesShell, 'Register')
    && mustContain($salesShell, "'url' => '/sales/register'");
$checks['workspace_shell_reports_tab_gated'] = mustContain($salesShell, "'id' => 'reports'")
    && mustContain($salesShell, "'url' => '/reports'")
    && mustContain($salesShell, 'reports.view');
$checks['workspace_manage_sales_points_to_orders_list'] = mustContain($salesShell, "'url' => '/sales/invoices'")
    && mustContain($salesShell, "'label' => 'Manage Sales'");
$checks['cashier_partial_has_no_legacy_cashier_subnav'] = !mustContain($cashierWorkspace, 'cashier-subnav');
$checks['register_route_still_registered'] = mustContain($routes, "get('/sales/register'");
$checks['scanner_source_disabled_honestly'] = mustContain($cashierWorkspace, 'scanner_input')
    && mustContain($cashierWorkspace, 'source_input')
    && mustContain($cashierWorkspace, 'readonly disabled placeholder="Disabled"');
$checks['cashier_edit_includes_workspace_shell'] = mustContain($cashierEdit, 'sales-workspace-shell.php')
    && mustContain($cashierEdit, "require __DIR__ . '/_cashier_workspace.php'");
$checks['cashier_edit_route_still_registered'] = mustContain($routes, "get('/sales/invoices/{id}/edit'");

$allPass = !in_array(false, $checks, true);

echo "=== sales_index_booker_style_home_surface_01 (IA split) ===\n";
foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo 'overall=' . ($allPass ? 'PASS' : 'FAIL') . "\n";
exit($allPass ? 0 : 1);
