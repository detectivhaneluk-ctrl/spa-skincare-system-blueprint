<?php

declare(strict_types=1);

/**
 * Read-only proof for CLIENTS-FIELDS-AND-PAGE-LAYOUTS-FOUNDATION-01.
 *
 * Usage (from system/):
 *   php scripts/read-only/proof_clients_fields_and_page_layouts_foundation_01.php
 */

$base = dirname(__DIR__, 2);

function mustContain(string $file, string $needle): bool
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';

    return $text !== '' && str_contains($text, $needle);
}

$routes = $base . '/routes/web/register_clients.php';
$controller = $base . '/modules/clients/controllers/ClientController.php';
$bootstrap = $base . '/modules/bootstrap/register_clients.php';
$migration = $base . '/data/migrations/113_clients_fields_layouts_and_extended_columns.sql';
$repo = $base . '/modules/clients/repositories/ClientRepository.php';
$edit = $base . '/modules/clients/views/edit.php';
$layoutRender = $base . '/modules/clients/views/partials/client-details-layout-render.php';
$sidebarLayout = $base . '/modules/clients/views/partials/client-sidebar-layout-fields.php';
$layoutsView = $base . '/modules/clients/views/custom-fields-layouts.php';
$fieldsIndex = $base . '/modules/clients/views/custom-fields-index.php';

$checks = [];

$checks['route_layouts_get'] = mustContain($routes, '/clients/custom-fields/layouts');
$checks['route_layouts_save_post'] = mustContain($routes, '/clients/custom-fields/layouts/save');
$checks['route_custom_field_delete'] = mustContain($routes, '/delete');
$checks['controller_layouts_methods'] = mustContain($controller, 'function customFieldsLayouts(')
    && mustContain($controller, 'function customFieldsLayoutsSave(')
    && mustContain($controller, 'function customFieldsDestroy(');
$checks['controller_parse_receive_emails_sms'] = mustContain($controller, "'receive_emails'")
    && mustContain($controller, "'receive_sms'");
$checks['bootstrap_page_layout_service'] = mustContain($bootstrap, 'ClientPageLayoutService::class');
$checks['migration_layout_tables'] = mustContain($migration, 'client_page_layout_profiles')
    && mustContain($migration, 'client_page_layout_items');
$checks['repo_normalize_anniversary'] = mustContain($repo, 'anniversary')
    && mustContain($repo, 'receive_sms');
$checks['edit_uses_layout_render'] = mustContain($edit, 'client-details-layout-render.php');
$checks['layout_render_receive_emails'] = mustContain($layoutRender, 'receive_emails')
    && mustContain($layoutRender, 'receive_sms');
$checks['sidebar_layout_partial'] = is_file($sidebarLayout) && mustContain($sidebarLayout, 'sidebarLayoutKeys');
$checks['layouts_view_save_and_remove_forms'] = mustContain($layoutsView, 'layouts/save')
    && mustContain($layoutsView, 'layouts/remove-item');
$checks['fields_index_system_catalog_table'] = mustContain($fieldsIndex, 'System fields (catalog)')
    && mustContain($fieldsIndex, '/delete');

$allPass = !in_array(false, $checks, true);

echo "=== clients_fields_and_page_layouts_foundation_01 ===\n";
foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo 'overall=' . ($allPass ? 'PASS' : 'FAIL') . "\n";
