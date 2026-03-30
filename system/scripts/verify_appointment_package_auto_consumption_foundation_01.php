<?php

declare(strict_types=1);

/**
 * APPOINTMENT-PACKAGE-AUTO-CONSUMPTION-FOUNDATION-01 — static proof (no DB).
 *
 * Foundation 01 is **blocked**: packages have no service-scoped entitlement in schema.
 * This script fails if that precondition is violated without updating the ops doc.
 *
 * From system/:
 *   php scripts/verify_appointment_package_auto_consumption_foundation_01.php
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function vPacPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vPacFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

$m36 = (string) file_get_contents($root . '/data/migrations/036_create_packages_table.sql');
if (!str_contains($m36, 'CREATE TABLE packages')) {
    vPacFail('migration_036', 'Expected 036_create_packages_table.sql with packages DDL');
} elseif (str_contains($m36, 'service_id') || str_contains($m36, 'services(')) {
    vPacFail('migration_036', 'packages DDL must not reference services/service_id (update ops doc if intentional)');
} else {
    vPacPass('packages_ddl_no_service_link');
}

$pkgMigConflict = false;
foreach (glob($root . '/data/migrations/*.sql') ?: [] as $sqlPath) {
    $sql = (string) file_get_contents($sqlPath);
    if ($sql === '') {
        continue;
    }
    if (preg_match('/ALTER\s+TABLE\s+packages\b/i', $sql) === 1 && str_contains($sql, 'service_id')) {
        $pkgMigConflict = true;
        vPacFail('migrations_packages_service', 'Migration references packages + service_id: ' . basename($sqlPath));
        break;
    }
}
if (!$pkgMigConflict) {
    vPacPass('no_migration_packages_service_column');
}

$snapshot = (string) file_get_contents($root . '/modules/packages/Support/PackageEntitlementSnapshot.php');
if (str_contains($snapshot, 'service_id') || str_contains($snapshot, 'covered_service')) {
    vPacFail('snapshot', 'PackageEntitlementSnapshot must not add service coverage without foundation charter');
} else {
    vPacPass('snapshot_no_service_coverage');
}

$avail = (string) file_get_contents($root . '/core/contracts/PackageAvailabilityProvider.php');
if (preg_match('/function\s+\w+\s*\([^)]*appointment/i', $avail)) {
    vPacFail('contract', 'PackageAvailabilityProvider must not add appointment-scoped methods without implementation charter');
} else {
    vPacPass('contract_scope');
}

$ps = (string) file_get_contents($root . '/modules/packages/services/PackageService.php');
if (!str_contains($ps, 'function listEligibleClientPackages')) {
    vPacFail('service', 'PackageService::listEligibleClientPackages missing');
} else {
    vPacPass('service_listEligible_exists');
}
// Eligibility must not claim service filtering (honest naming in body would use service_id in SQL — none expected)
if (preg_match('/function\s+listEligibleClientPackages\b[\s\S]{0,2500}service_id/s', $ps)) {
    vPacFail('service_filter', 'listEligibleClientPackages must not filter by service_id until package-service entitlement exists');
} else {
    vPacPass('listEligible_not_service_scoped');
}

$consume = (string) file_get_contents($root . '/modules/appointments/services/AppointmentService.php');
// No internal calls to consumePackageSessions from service (explicit POST path only).
if (substr_count($consume, '->consumePackageSessions(') !== 0) {
    vPacFail('lifecycle', 'AppointmentService must not invoke consumePackageSessions internally (no hidden auto)');
} else {
    vPacPass('no_internal_consumePackageSessions');
}

$ops = (string) file_get_contents($root . '/docs/APPOINTMENT-PACKAGE-AUTO-CONSUMPTION-FOUNDATION-01-OPS.md');
if (!str_contains($ops, 'Blocked') || !str_contains($ops, 'package ↔ service')) {
    vPacFail('ops_doc', 'Ops doc must record blocked verdict and service linkage gap');
} else {
    vPacPass('ops_doc_truth');
}

echo "\nDone. Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
