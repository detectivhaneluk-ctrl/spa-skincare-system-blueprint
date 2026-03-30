<?php

declare(strict_types=1);

/**
 * FND-TNT-19 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-13
 * hasRoomConflict (concrete branch): appointments alias + branchColumnOwnedByResolvedOrganizationExistsClause('a').
 */

$root = dirname(__DIR__, 3);
$repo = (string) file_get_contents($root . '/system/modules/appointments/repositories/AppointmentRepository.php');

$pos = strpos($repo, 'function hasRoomConflict');
if ($pos === false) {
    fwrite(STDERR, "FAIL: AppointmentRepository missing hasRoomConflict.\n");
    exit(1);
}
$fn = substr($repo, $pos, 4200);

$ok = true;
if (!str_contains($fn, 'branchColumnOwnedByResolvedOrganizationExistsClause(\'a\')')) {
    fwrite(STDERR, "FAIL: hasRoomConflict must use branchColumnOwnedByResolvedOrganizationExistsClause('a') for scoped branch path.\n");
    $ok = false;
}
if (!str_contains($fn, 'if ($branchId !== null)')) {
    fwrite(STDERR, "FAIL: hasRoomConflict must branch on non-null branchId for tenant-scoped SQL.\n");
    $ok = false;
}
if (!str_contains($fn, 'FROM appointments a')) {
    fwrite(STDERR, "FAIL: hasRoomConflict must use appointments alias a.\n");
    $ok = false;
}
if (!str_contains($fn, 'AND a.branch_id IS NULL')) {
    fwrite(STDERR, "FAIL: hasRoomConflict must retain explicit legacy null-branch arm (a.branch_id IS NULL).\n");
    $ok = false;
}
if (str_contains($fn, 'SELECT 1 FROM appointments
                WHERE deleted_at IS NULL')) {
    fwrite(STDERR, "FAIL: hasRoomConflict must not use legacy unaliased appointments SELECT.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
