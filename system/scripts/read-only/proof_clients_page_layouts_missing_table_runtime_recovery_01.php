<?php

declare(strict_types=1);

/**
 * Read-only proof for CLIENTS-PAGE-LAYOUTS-MISSING-TABLE-RUNTIME-RECOVERY-01.
 *
 * Usage (from system/):
 *   php scripts/read-only/proof_clients_page_layouts_missing_table_runtime_recovery_01.php
 */

$base = dirname(__DIR__, 2);

function mustContain(string $file, string $needle): bool
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';

    return $text !== '' && str_contains($text, $needle);
}

$migration = $base . '/data/migrations/113_clients_fields_layouts_and_extended_columns.sql';
$service = $base . '/modules/clients/services/ClientPageLayoutService.php';
$controller = $base . '/modules/clients/controllers/ClientController.php';
$shell = $base . '/modules/clients/views/partials/client-fields-admin-shell.php';
$layoutsView = $base . '/modules/clients/views/custom-fields-composer.php';

$checks = [];

$checks['migration_113_creates_profiles'] = mustContain($migration, 'CREATE TABLE client_page_layout_profiles');
$checks['migration_113_creates_items'] = mustContain($migration, 'CREATE TABLE client_page_layout_items');
$checks['service_isLayoutStorageReady'] = mustContain($service, 'function isLayoutStorageReady(')
    && mustContain($service, 'information_schema.tables');
$checks['service_ensureDefaults_guarded'] = mustContain($service, 'ensureDefaultsForOrganization')
    && mustContain($service, 'if (!$this->isLayoutStorageReady())')
    && preg_match('/function ensureDefaultsForOrganization[\s\S]*?if \(!\$this->isLayoutStorageReady\(\)\)/', (string) file_get_contents($service)) === 1;
$checks['service_saveLayout_guarded'] = mustContain($service, 'function saveLayout(')
    && mustContain($service, 'LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE');
$checks['controller_post_guarded'] = mustContain($controller, 'customFieldsLayoutsSave')
    && mustContain($controller, 'isLayoutStorageReady()')
    && mustContain($controller, 'LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE');
$checks['controller_get_shift_guarded'] = mustContain($controller, 'shift_field')
    && mustContain($controller, 'if (!$layoutStorageReady)');
$checks['shell_operator_notice'] = mustContain($shell, 'LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE')
    && mustContain($shell, 'scripts/migrate.php');
$checks['layouts_view_blocked_branch'] = mustContain($layoutsView, 'layoutStorageReady')
    && mustContain($layoutsView, 'Layout storage is not available');

$allPass = !in_array(false, $checks, true);

echo "=== clients_page_layouts_missing_table_runtime_recovery_01 ===\n";
foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo 'overall=' . ($allPass ? 'PASS' : 'FAIL') . "\n";
