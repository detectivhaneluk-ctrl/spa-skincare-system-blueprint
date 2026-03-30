<?php

declare(strict_types=1);

/**
 * Read-only proof for CLIENTS-DUPLICATE-SEARCH-PAGE-FOUNDATION-01.
 *
 * Usage (from system/):
 *   php scripts/read-only/proof_clients_duplicate_search_page_foundation_01.php
 */

$base = dirname(__DIR__, 2);

function mustContain(string $file, string $needle): bool
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';

    return $text !== '' && str_contains($text, $needle);
}

$routes = $base . '/routes/web/register_clients.php';
$controller = $base . '/modules/clients/controllers/ClientController.php';
$service = $base . '/modules/clients/services/ClientService.php';
$indexView = $base . '/modules/clients/views/index.php';
$dupView = $base . '/modules/clients/views/duplicates.php';
$wsData = $base . '/modules/clients/views/partials/clients-workspace-data.php';

$checks = [];

$checks['route_get_clients_duplicates'] = mustContain($routes, "'/clients/duplicates'")
    && mustContain($routes, 'duplicates');
$checks['controller_duplicates_method'] = mustContain($controller, 'function duplicates()');
$checks['controller_calls_search_duplicate_candidates'] = mustContain($controller, 'searchDuplicateCandidatesPaginated');
$checks['service_search_duplicate_candidates_paginated'] = mustContain($service, 'function searchDuplicateCandidatesPaginated');
$checks['view_duplicates_exists'] = is_file($dupView);
$checks['workspace_subnav_includes_duplicate_search'] = mustContain($wsData, "'/clients/duplicates'")
    && mustContain($wsData, 'Duplicate Search');
$checks['index_uses_workspace_data_partial'] = mustContain($indexView, 'clients-workspace-data.php');
$checks['index_no_inline_dup_panel'] = !mustContain($indexView, 'clients-ws-secondary-panel')
    && !mustContain($indexView, 'dup_name');
$checks['index_links_to_duplicate_page'] = mustContain($indexView, '/clients/duplicates');
$checks['duplicates_view_merge_action_clients_merge'] = mustContain($dupView, 'action="/clients/merge"')
    && mustContain($dupView, 'primary_id')
    && mustContain($dupView, 'secondary_id');

$allPass = !in_array(false, $checks, true);

echo "=== clients_duplicate_search_page_foundation_01 ===\n";
foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo 'overall=' . ($allPass ? 'PASS' : 'FAIL') . "\n";
