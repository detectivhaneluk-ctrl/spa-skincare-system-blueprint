<?php

declare(strict_types=1);

/**
 * FND-TNT-21 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-14
 * hasStaffConflict: appointments alias + branchColumnOwnedByResolvedOrganizationExistsClause('a').
 * (Serial FND-TNT-21 in tenant-closure file series; inventory gate uses separate FND-TNT-20 on product weak-list verifier.)
 */

$root = dirname(__DIR__, 3);
$repo = (string) file_get_contents($root . '/system/modules/appointments/repositories/AppointmentRepository.php');

$pos = strpos($repo, 'function hasStaffConflict');
if ($pos === false) {
    fwrite(STDERR, "FAIL: AppointmentRepository missing hasStaffConflict.\n");
    exit(1);
}
$fn = substr($repo, $pos, 2400);

$ok = true;
if (!str_contains($fn, 'branchColumnOwnedByResolvedOrganizationExistsClause(\'a\')')) {
    fwrite(STDERR, "FAIL: hasStaffConflict must use branchColumnOwnedByResolvedOrganizationExistsClause('a').\n");
    $ok = false;
}
if (!str_contains($fn, 'FROM appointments a')) {
    fwrite(STDERR, "FAIL: hasStaffConflict must use appointments alias a.\n");
    $ok = false;
}
if (!str_contains($fn, 'a.staff_id = ?')) {
    fwrite(STDERR, "FAIL: hasStaffConflict must qualify staff_id with alias a.\n");
    $ok = false;
}
if (str_contains($fn, 'SELECT 1 FROM appointments
                WHERE deleted_at IS NULL')) {
    fwrite(STDERR, "FAIL: hasStaffConflict must not use legacy unaliased appointments SELECT.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
