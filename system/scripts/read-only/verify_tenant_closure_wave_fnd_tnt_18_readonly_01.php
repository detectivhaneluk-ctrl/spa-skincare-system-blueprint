<?php

declare(strict_types=1);

/**
 * FND-TNT-18 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-12
 * Room serialization lock for appointment conflict checks: tenant org-scoped rooms row (not id-only global lock).
 */

$root = dirname(__DIR__, 3);
$repo = (string) file_get_contents($root . '/system/modules/appointments/repositories/AppointmentRepository.php');

$ok = true;
$pos = strpos($repo, 'function lockRoomRowForConflictCheck');
if ($pos === false) {
    fwrite(STDERR, "FAIL: AppointmentRepository missing lockRoomRowForConflictCheck.\n");
    exit(1);
}
$fn = substr($repo, $pos, 1200);

if (!str_contains($fn, 'branchColumnOwnedByResolvedOrganizationExistsClause(\'r\')')) {
    fwrite(STDERR, "FAIL: lockRoomRowForConflictCheck must use branchColumnOwnedByResolvedOrganizationExistsClause('r').\n");
    $ok = false;
}
if (!str_contains($fn, 'FROM rooms r')) {
    fwrite(STDERR, "FAIL: lockRoomRowForConflictCheck must query rooms with alias r.\n");
    $ok = false;
}
if (!str_contains($fn, 'r.deleted_at IS NULL')) {
    fwrite(STDERR, "FAIL: lockRoomRowForConflictCheck must filter r.deleted_at IS NULL.\n");
    $ok = false;
}
if (!str_contains($fn, 'FOR UPDATE')) {
    fwrite(STDERR, "FAIL: lockRoomRowForConflictCheck must retain FOR UPDATE.\n");
    $ok = false;
}
if (str_contains($fn, 'SELECT id FROM rooms WHERE id = ?')) {
    fwrite(STDERR, "FAIL: lockRoomRowForConflictCheck must not use legacy unscoped rooms id-only SELECT.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
