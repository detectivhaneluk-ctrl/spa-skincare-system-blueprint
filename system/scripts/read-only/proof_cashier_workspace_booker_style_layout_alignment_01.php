<?php

declare(strict_types=1);

/**
 * Read-only proof for CASHIER-WORKSPACE-BOOKER-STYLE-LAYOUT-ALIGNMENT-01 (current strings).
 *
 * Usage (from system/):
 *   php scripts/read-only/proof_cashier_workspace_booker_style_layout_alignment_01.php
 */

$base = dirname(__DIR__, 2);

function mustContain(string $file, string $needle): bool
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';

    return $text !== '' && str_contains($text, $needle);
}

$checks = [];

$createView = $base . '/modules/sales/views/invoices/create.php';
$editView = $base . '/modules/sales/views/invoices/edit.php';
$workspaceView = $base . '/modules/sales/views/invoices/_cashier_workspace.php';
$invoiceController = $base . '/modules/sales/controllers/InvoiceController.php';
$salesRoutes = $base . '/routes/web/register_sales_public_commerce_staff.php';

$checks['create_uses_shared_cashier_partial'] = mustContain($createView, "require __DIR__ . '/_cashier_workspace.php'");
$checks['edit_uses_shared_cashier_partial'] = mustContain($editView, "require __DIR__ . '/_cashier_workspace.php'");
$checks['create_and_edit_use_cashier_main_class'] = mustContain($createView, '$mainClass = \'cashier-workspace-page\'') && mustContain($editView, '$mainClass = \'cashier-workspace-page\'');
$checks['workspace_has_left_rail'] = mustContain($workspaceView, 'cashier-left-rail');
$checks['workspace_has_client_banner'] = mustContain($workspaceView, 'cashier-client-banner');
$checks['workspace_has_ordered_articles_header'] = mustContain($workspaceView, 'Ordered articles');
$checks['workspace_has_tab_order_reference'] = mustContain($workspaceView, 'data-tab-target="tab-products"')
    && mustContain($workspaceView, 'data-tab-target="tab-services"')
    && mustContain($workspaceView, 'data-tab-target="tab-deferred"')
    && mustContain($workspaceView, 'data-tab-target="tab-membership"')
    && mustContain($workspaceView, 'data-tab-target="tab-tips"');
$checks['workspace_deferred_tab_honest_copy'] = mustContain($workspaceView, 'Gift Card / Card / Series / Client Account')
    && mustContain($workspaceView, 'stay read-only here until audited line-storage support exists');
$checks['workspace_scanner_source_are_disabled'] = mustContain($workspaceView, 'scanner_input')
    && mustContain($workspaceView, 'readonly disabled')
    && mustContain($workspaceView, 'source_input');
$checks['workspace_product_add_maps_real_line_payload'] = mustContain($workspaceView, "item_type: 'product'")
    && mustContain($workspaceView, 'source_id: product.id');
$checks['workspace_service_add_maps_real_line_payload'] = mustContain($workspaceView, "item_type: 'service'")
    && mustContain($workspaceView, 'source_id: service.id');
$checks['workspace_tip_add_uses_manual_semantics'] = mustContain($workspaceView, "item_type: 'manual'")
    && mustContain($workspaceView, "description: 'TIP: ' + desc");
$checks['membership_guard_still_client_required'] = mustContain($invoiceController, 'Client is required to sell a membership.');
$checks['membership_guard_still_standalone'] = mustContain($invoiceController, 'Membership checkout is standalone');
$checks['cashier_routes_still_present'] = mustContain($salesRoutes, "get('/sales/invoices/create'")
    && mustContain($salesRoutes, "post('/sales/invoices'")
    && mustContain($salesRoutes, "get('/sales/invoices/{id}/edit'")
    && mustContain($salesRoutes, "post('/sales/invoices/{id}'");

$allPass = !in_array(false, $checks, true);

echo "=== cashier_workspace_booker_style_layout_alignment_01 (current) ===\n";
foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo 'overall=' . ($allPass ? 'PASS' : 'FAIL') . "\n";
