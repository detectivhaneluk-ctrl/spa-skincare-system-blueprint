<?php

declare(strict_types=1);

/**
 * SCALABILITY-HOT-TABLE-QUERY-AND-INDEX-AUDIT-01 — static lock that migration 114 + full_project_schema
 * declare the additive operational index contract (wave 01).
 *
 * From repo root:
 *   php system/scripts/read-only/verify_scalability_hot_table_indexes_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$m114 = $system . '/data/migrations/114_scalability_hot_operational_indexes_wave_01.sql';
$schema = $system . '/data/full_project_schema.sql';

$m114Body = is_file($m114) ? (string) file_get_contents($m114) : '';
$schemaBody = is_file($schema) ? (string) file_get_contents($schema) : '';

$indexNames = [
    'idx_invoices_branch_deleted_created',
    'idx_invoices_client_deleted_created',
    'idx_payments_invoice_created',
    'idx_payments_register_session_method_status',
    'idx_payments_parent_entry_status',
    'idx_invoice_items_invoice_sort',
    'idx_appointments_branch_deleted_start',
    'idx_appointments_staff_deleted_start',
    'idx_clients_branch_deleted_name',
];

$checks = [];

$checks['Migration 114 exists and references audit wave'] = $m114Body !== ''
    && str_contains($m114Body, 'SCALABILITY-HOT-TABLE-QUERY-AND-INDEX-AUDIT-01');

foreach ($indexNames as $name) {
    $checks["Migration 114 defines {$name}"] = str_contains($m114Body, $name);
}

foreach ($indexNames as $name) {
    $checks["full_project_schema.sql defines {$name}"] = str_contains($schemaBody, $name);
}

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

echo PHP_EOL . 'Scalability hot-table index wave 01 static checks passed.' . PHP_EOL;
exit(0);
