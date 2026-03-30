<?php

declare(strict_types=1);

/**
 * Read-only proof for SALES-STAFF-CHECKOUT-WORKSPACE-FOUNDATION-01.
 *
 * Usage (from system/):
 *   php scripts/read-only/proof_staff_checkout_workspace_foundation_01.php
 */

$base = dirname(__DIR__, 2);

function mustContain(string $file, string $needle): bool
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';
    return $text !== '' && str_contains($text, $needle);
}

$checks = [];

$invoiceController = $base . '/modules/sales/controllers/InvoiceController.php';
$workspaceView = $base . '/modules/sales/views/invoices/_cashier_workspace.php';
$bootstrap = $base . '/modules/bootstrap/register_sales_public_commerce_memberships_settings.php';

$checks['controller_accepts_product_item_type'] = mustContain($invoiceController, "['service', 'manual', 'product']");
$checks['controller_loads_branch_scoped_products'] = mustContain($invoiceController, 'listActiveForUnifiedCatalog');
$checks['controller_membership_standalone_guard'] = mustContain($invoiceController, 'Membership checkout is standalone');
$checks['view_has_products_tab'] = mustContain($workspaceView, "data-tab-target=\"tab-products\"");
$checks['view_has_services_tab'] = mustContain($workspaceView, "data-tab-target=\"tab-services\"");
$checks['view_has_tips_tab'] = mustContain($workspaceView, "data-tab-target=\"tab-tips\"");
$checks['view_has_membership_tab'] = mustContain($workspaceView, "data-tab-target=\"tab-membership\"");
$checks['view_has_honest_deferred_tab_group'] = mustContain($workspaceView, 'Gift Card / Series / Client Account (Deferred)');
$checks['view_adds_product_line_with_source'] = mustContain($workspaceView, "item_type: 'product'") && mustContain($workspaceView, 'source_id: product.id');
$checks['view_adds_service_line_with_source'] = mustContain($workspaceView, "item_type: 'service'") && mustContain($workspaceView, 'source_id: service.id');
$checks['view_adds_tip_manual_line'] = mustContain($workspaceView, "description: 'TIP: ' + desc");
$checks['bootstrap_invoice_controller_wiring_updated'] = mustContain($bootstrap, '\\Modules\\Inventory\\Repositories\\ProductRepository::class');

$allPass = !in_array(false, $checks, true);

echo "=== staff_checkout_workspace_foundation_01 ===\n";
foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo 'overall=' . ($allPass ? 'PASS' : 'FAIL') . "\n";

