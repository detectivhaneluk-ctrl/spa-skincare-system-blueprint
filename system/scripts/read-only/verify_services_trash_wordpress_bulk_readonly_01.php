<?php

declare(strict_types=1);

/**
 * SERVICES-BULK-TRASH-WORDPRESS-STYLE-01 — static read-only verifier (no DB).
 *
 * Run from repo root:
 *   php system/scripts/read-only/verify_services_trash_wordpress_bulk_readonly_01.php
 */

$repoRoot = dirname(__DIR__, 3);
$system   = $repoRoot . '/system';

$routes = (string) file_get_contents($system . '/routes/web/register_services_resources.php');
$ctl    = (string) file_get_contents($system . '/modules/services-resources/controllers/ServiceController.php');
$repo   = (string) file_get_contents($system . '/modules/services-resources/repositories/ServiceRepository.php');
$svc    = (string) file_get_contents($system . '/modules/services-resources/services/ServiceService.php');
$idx    = (string) file_get_contents($system . '/modules/services-resources/views/services/index.php');
$mig    = (string) file_get_contents($system . '/data/migrations/142_services_trash_metadata.sql');
$cli    = (string) file_get_contents($system . '/scripts/purge_services_trash_cli_01.php');
$cfg    = (string) file_get_contents($system . '/config/services.php');

$checks = [
    'Migration 142 defines deleted_by + purge_after_at' =>
        str_contains($mig, 'deleted_by') && str_contains($mig, 'purge_after_at'),
    'Config services.trash_retention_days' =>
        str_contains($cfg, 'trash_retention_days'),
    'Routes: bulk-trash, bulk-restore, bulk-permanent-delete' =>
        str_contains($routes, '/services-resources/services/bulk-trash')
        && str_contains($routes, '/services-resources/services/bulk-restore')
        && str_contains($routes, '/services-resources/services/bulk-permanent-delete'),
    'Routes: restore + permanent-delete' =>
        str_contains($routes, '/services-resources/services/{id}/restore')
        && str_contains($routes, '/services-resources/services/{id}/permanent-delete'),
    'ServiceRepository: trash + bulkTrash + findTrashed + restore + hardDeleteTrashed + purge list' =>
        str_contains($repo, 'function trash(')
        && str_contains($repo, 'function bulkTrash(')
        && str_contains($repo, 'function findTrashed(')
        && str_contains($repo, 'function restore(')
        && str_contains($repo, 'function hardDeleteTrashed(')
        && str_contains($repo, 'function listTrashedIdsEligibleForPurge('),
    'ServiceRepository list/count support trashOnly' =>
        str_contains($repo, 'bool $trashOnly = false'),
    'ServiceService: bulkTrash restore permanentlyDelete purgeExpiredTrashedBatch' =>
        str_contains($svc, 'function bulkTrash(')
        && str_contains($svc, 'function restore(')
        && str_contains($svc, 'function permanentlyDelete(')
        && str_contains($svc, 'function purgeExpiredTrashedBatch('),
    'ServiceController: bulkTrash bulkRestore bulkPermanentDelete restore permanentDelete' =>
        str_contains($ctl, 'function bulkTrash(')
        && str_contains($ctl, 'function bulkRestore(')
        && str_contains($ctl, 'function bulkPermanentDelete(')
        && str_contains($ctl, 'function restore(')
        && str_contains($ctl, 'function permanentDelete('),
    'Index view: header checkbox + bulk Move to Trash + Trash filter' =>
        str_contains($idx, 'svc-check-all')
        && str_contains($idx, 'move_to_trash')
        && str_contains($idx, 'status=trash'),
    'CLI purge script: organization scoping flags' =>
        str_contains($cli, '--organization-id=') && str_contains($cli, '--all-organizations'),
];

$fail = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $fail = true;
    }
}

exit($fail ? 1 : 0);
