<?php

declare(strict_types=1);

/**
 * Read-only proof for clients duplicate UX (inline intelligence + New Client phone hint).
 * Replaces legacy dedicated Duplicate Search page checks.
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

function mustNotContain(string $file, string $needle): bool
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';

    return $text === '' || !str_contains($text, $needle);
}

$routes = $base . '/routes/web/register_clients.php';
$controller = $base . '/modules/clients/controllers/ClientController.php';
$service = $base . '/modules/clients/services/ClientService.php';
$indexView = $base . '/modules/clients/views/index.php';
$dupView = $base . '/modules/clients/views/duplicates.php';
$wsData = $base . '/modules/clients/views/partials/clients-workspace-data.php';
$formFields = $base . '/modules/clients/views/partials/client-create-form-fields.php';
$mergeView = $base . '/modules/clients/views/merge-preview.php';

$checks = [];

$checks['route_no_merge_preview_get'] = mustNotContain(
    $routes,
    "->get('/clients/merge', [\Modules\Clients\Controllers\ClientController::class, 'mergePreview']"
);
$checks['route_post_clients_merge_kept'] = mustContain($routes, "->post('/clients/merge',");
$checks['view_merge_preview_removed'] = !is_file($mergeView);
$checks['controller_no_merge_preview_method'] = mustNotContain($controller, 'function mergePreview()');
$checks['index_merge_modal_shell'] = mustContain($indexView, 'cli-merge-modal')
    && mustContain($indexView, 'Merge Duplicate Clients')
    && mustContain($indexView, 'client-merge-modal.js');
$checks['route_no_clients_duplicates'] = mustNotContain($routes, "'/clients/duplicates'");
$checks['route_get_clients_phone_exists'] = mustContain($routes, "'/clients/phone-exists'")
    && mustContain($routes, 'phoneExistsCheck');
$checks['controller_phone_exists_method'] = mustContain($controller, 'function phoneExistsCheck()');
$checks['controller_no_duplicates_method'] = mustNotContain($controller, 'function duplicates()');
$checks['service_find_duplicates_for_phone_hint'] = mustContain($service, 'function findDuplicates(');
$checks['view_duplicates_removed'] = !is_file($dupView);
$checks['workspace_subnav_no_duplicate_search'] = mustNotContain($wsData, "'/clients/duplicates'")
    && mustNotContain($wsData, 'Duplicate Search');
$checks['index_uses_workspace_data_partial'] = mustContain($indexView, 'clients-workspace-data.php');
$checks['index_inline_dup_banner'] = mustContain($indexView, 'cli-dup-intel-banner')
    && mustContain($indexView, 'duplicate client')
    && mustContain($indexView, 'same name, phone, and email')
    && mustNotContain($indexView, '/clients/duplicates');
$checks['index_no_toolbar_dup_link'] = mustNotContain($indexView, 'Duplicate Search');
$checks['create_form_phone_dedupe_markup'] = mustContain($formFields, 'js-client-phone-dedupe-input')
    && mustContain($formFields, 'client-phone-dedupe-hint');

$allPass = !in_array(false, $checks, true);

echo "=== clients_duplicate_intelligence_surface_foundation ===\n";
foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo 'overall=' . ($allPass ? 'PASS' : 'FAIL') . "\n";
