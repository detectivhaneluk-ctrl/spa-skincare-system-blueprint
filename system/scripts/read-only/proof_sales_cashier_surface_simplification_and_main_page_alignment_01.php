<?php

declare(strict_types=1);

/**
 * Read-only proof for cashier workspace shells + sales entry = cashier workspace (current).
 *
 * Usage (from system/):
 *   php scripts/read-only/proof_sales_cashier_surface_simplification_and_main_page_alignment_01.php
 */

$base = dirname(__DIR__, 2);

function mustContain(string $file, string $needle): bool
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';

    return $text !== '' && str_contains($text, $needle);
}

$checks = [];

$salesController = $base . '/modules/sales/controllers/SalesController.php';
$salesIndex = $base . '/modules/sales/views/index.php';
$createView = $base . '/modules/sales/views/invoices/create.php';
$editView = $base . '/modules/sales/views/invoices/edit.php';
$workspaceView = $base . '/modules/sales/views/invoices/_cashier_workspace.php';
$invoiceController = $base . '/modules/sales/controllers/InvoiceController.php';
$routes = $base . '/routes/web/register_sales_public_commerce_staff.php';

$checks['sales_entry_delegates_to_invoice_cashier_prep'] = mustContain($salesController, 'InvoiceController')
    && mustContain($salesController, 'staffCheckoutFromSalesRoute')
    && !mustContain($salesController, "header('Location: /sales/invoices'");
$checks['canonical_cashier_prep_in_invoice_controller'] = mustContain($invoiceController, 'renderNewSaleCashierWorkspace')
    && mustContain($invoiceController, "modules/sales/views/invoices/create.php");
$checks['legacy_sales_index_view_optional'] = !is_file($salesIndex)
    || mustContain($salesIndex, "require __DIR__ . '/invoices/_cashier_workspace.php'");
$checks['create_view_uses_shared_cashier_partial'] = mustContain($createView, "require __DIR__ . '/_cashier_workspace.php'");
$checks['edit_view_uses_shared_cashier_partial'] = mustContain($editView, "require __DIR__ . '/_cashier_workspace.php'");
$checks['shared_workspace_has_required_surface_regions'] = mustContain($workspaceView, 'cashier-left-rail')
    && mustContain($workspaceView, 'cashier-client-banner')
    && mustContain($workspaceView, 'Ordered articles')
    && mustContain($workspaceView, 'cashier-tab-row');
$checks['product_add_maps_to_real_invoice_storage_contract'] = mustContain($workspaceView, "item_type: 'product'")
    && mustContain($workspaceView, 'source_id: product.id');
$checks['service_add_maps_to_real_invoice_storage_contract'] = mustContain($workspaceView, "item_type: 'service'")
    && mustContain($workspaceView, 'source_id: service.id');
$checks['line_technical_fields_preserved_as_hidden_inputs'] = mustContain($workspaceView, 'name="items[')
    && mustContain($workspaceView, '[item_type]"')
    && mustContain($workspaceView, '[source_id]"')
    && mustContain($workspaceView, '[discount_amount]"')
    && mustContain($workspaceView, '[tax_rate]"');
$checks['unsupported_tab_remains_honest_deferred'] = mustContain($workspaceView, 'Gift Card / Card / Series / Client Account')
    && mustContain($workspaceView, 'stay read-only here until audited line-storage support exists.');
$checks['scanner_and_source_stay_disabled'] = mustContain($workspaceView, 'id="scanner_input"')
    && mustContain($workspaceView, 'id="source_input"')
    && mustContain($workspaceView, 'scanner_input')
    && mustContain($workspaceView, 'readonly disabled');
$checks['create_edit_routes_still_present'] = mustContain($routes, "get('/sales/invoices/create'")
    && mustContain($routes, "post('/sales/invoices'")
    && mustContain($routes, "get('/sales/invoices/{id}/edit'")
    && mustContain($routes, "post('/sales/invoices/{id}'");
$checks['invoice_semantic_guards_still_present'] = mustContain($invoiceController, 'Membership checkout is standalone')
    && mustContain($invoiceController, 'Client is required to sell a membership.');

$allPass = !in_array(false, $checks, true);

echo "=== sales_cashier_surface_simplification_and_main_page_alignment_01 ===\n";
foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo 'overall=' . ($allPass ? 'PASS' : 'FAIL') . "\n";
exit($allPass ? 0 : 1);
