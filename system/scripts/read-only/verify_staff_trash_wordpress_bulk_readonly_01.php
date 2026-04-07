<?php

declare(strict_types=1);

/**
 * STAFF-BULK-TRASH-WORDPRESS-STYLE-01 — static read-only verifier (no DB).
 *
 *   php system/scripts/read-only/verify_staff_trash_wordpress_bulk_readonly_01.php
 */

$repoRoot = dirname(__DIR__, 3);
$system   = $repoRoot . '/system';

$routes = (string) file_get_contents($system . '/routes/web/register_staff.php');
$ctl    = (string) file_get_contents($system . '/modules/staff/controllers/StaffController.php');
$repo   = (string) file_get_contents($system . '/modules/staff/repositories/StaffRepository.php');
$svc    = (string) file_get_contents($system . '/modules/staff/services/StaffService.php');
$idx    = (string) file_get_contents($system . '/modules/staff/views/index.php');
$mig    = (string) file_get_contents($system . '/data/migrations/143_staff_trash_metadata.sql');
$cli    = (string) file_get_contents($system . '/scripts/purge_staff_trash_cli_01.php');
$cfg    = (string) file_get_contents($system . '/config/staff.php');

$checks = [
    'Migration 143 defines deleted_by + purge_after_at' =>
        str_contains($mig, 'deleted_by') && str_contains($mig, 'purge_after_at'),
    'Config staff.trash_retention_days' =>
        str_contains($cfg, 'trash_retention_days'),
    'Routes: bulk-trash, bulk-restore, bulk-permanent-delete' =>
        str_contains($routes, '/staff/bulk-trash')
        && str_contains($routes, '/staff/bulk-restore')
        && str_contains($routes, '/staff/bulk-permanent-delete'),
    'Routes: restore + permanent-delete' =>
        str_contains($routes, 'StaffController::class, \'restore\'')
        && str_contains($routes, 'permanentDelete'),
    'StaffRepository: trash + bulkTrash + findTrashed + restore + hardDeleteTrashed + purge list' =>
        str_contains($repo, 'function trash(')
        && str_contains($repo, 'function bulkTrash(')
        && str_contains($repo, 'function findTrashed(')
        && str_contains($repo, 'function restore(')
        && str_contains($repo, 'function hardDeleteTrashed(')
        && str_contains($repo, 'function listTrashedIdsEligibleForPurge('),
    'StaffRepository list/count support trashOnly' =>
        str_contains($repo, 'bool $trashOnly = false'),
    'StaffService: bulkTrash restore permanentlyDelete purgeExpiredTrashedBatch' =>
        str_contains($svc, 'function bulkTrash(')
        && str_contains($svc, 'function restore(')
        && str_contains($svc, 'function permanentlyDelete(')
        && str_contains($svc, 'function purgeExpiredTrashedBatch('),
    'StaffController: bulkTrash bulkRestore bulkPermanentDelete restore permanentDelete' =>
        str_contains($ctl, 'function bulkTrash(')
        && str_contains($ctl, 'function bulkRestore(')
        && str_contains($ctl, 'function bulkPermanentDelete(')
        && str_contains($ctl, 'function restore(')
        && str_contains($ctl, 'function permanentDelete('),
    'Index view: header checkbox + bulk Move to Trash + Trash filter' =>
        str_contains($idx, 'stf-check-all')
        && str_contains($idx, 'move_to_trash')
        && str_contains($idx, 'status=trash'),
    'CLI purge script: organization scoping flags' =>
        str_contains($cli, '--organization-id=') && str_contains($cli, '--all-organizations'),
    'StaffService blocks hard delete when appointment_series or payroll lines exist' =>
        str_contains($svc, 'countAppointmentSeriesForStaff')
        && str_contains($svc, 'countPayrollCommissionLinesForStaff'),
    'StaffRepository hardDeleteTrashed issues physical DELETE on trashed rows only' =>
        str_contains($repo, 'DELETE s FROM staff s WHERE s.id = ? AND s.deleted_at IS NOT NULL'),
];

$fail = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $fail = true;
    }
}

exit($fail ? 1 : 0);
