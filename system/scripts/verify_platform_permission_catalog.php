<?php

declare(strict_types=1);

/**
 * FOUNDATION-39 — read-only verifier: platform + organization-profile permission catalog.
 *
 * Usage (from `system/`):
 *   php scripts/verify_platform_permission_catalog.php
 *   php scripts/verify_platform_permission_catalog.php --json
 *
 * Exit codes:
 *   0 — migration + seeder sources contain codes; DB has rows; legacy permission sample intact
 *   1 — missing file, missing code in source or DB, or legacy permission removed
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';

$json = in_array('--json', array_slice($argv, 1), true);

$requiredCodes = [
    'platform.organizations.view',
    'platform.organizations.manage',
    'organizations.profile.manage',
];

$legacyProbe = 'branches.view';

$migrationFile = $systemPath . '/data/migrations/088_platform_organization_profile_permissions_catalog.sql';
$seederFile = $systemPath . '/data/seeders/001_seed_roles_permissions.php';

$sourceErrors = [];

if (!is_file($migrationFile)) {
    $sourceErrors[] = 'missing migration file 088_platform_organization_profile_permissions_catalog.sql';
} else {
    $migrationSrc = (string) file_get_contents($migrationFile);
    foreach ($requiredCodes as $code) {
        if (!str_contains($migrationSrc, "'" . $code . "'")) {
            $sourceErrors[] = "migration source missing permission code: {$code}";
        }
    }
}

if (!is_file($seederFile)) {
    $sourceErrors[] = 'missing seeder 001_seed_roles_permissions.php';
} else {
    $seederSrc = (string) file_get_contents($seederFile);
    foreach ($requiredCodes as $code) {
        if (!str_contains($seederSrc, "'" . $code . "'")) {
            $sourceErrors[] = "seeder source missing permission code: {$code}";
        }
    }
}

$databaseErrors = [];
try {
    $pdo = app(\Core\App\Database::class)->connection();
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($dbName) || $dbName === '') {
        $databaseErrors[] = 'no database selected';
    } else {
        foreach ($requiredCodes as $code) {
            $stmt = $pdo->prepare('SELECT id FROM permissions WHERE code = ? LIMIT 1');
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() === false) {
                $databaseErrors[] = "permissions row missing in DB: {$code} (run migrate.php)";
            }
        }
        $stmt = $pdo->prepare('SELECT id FROM permissions WHERE code = ? LIMIT 1');
        $stmt->execute([$legacyProbe]);
        if ($stmt->fetchColumn() === false) {
            $databaseErrors[] = "legacy permission missing (accidental removal check): {$legacyProbe}";
        }
    }
} catch (Throwable $e) {
    $databaseErrors[] = 'db check failed: ' . $e->getMessage();
}

$errors = array_merge($sourceErrors, $databaseErrors);
$ok = $errors === [];

$payload = [
    'verifier' => 'verify_platform_permission_catalog',
    'foundation_wave' => 'FOUNDATION-39',
    'required_codes' => $requiredCodes,
    'legacy_probe' => $legacyProbe,
    'migration_file' => '088_platform_organization_profile_permissions_catalog.sql',
    'seeder_file' => '001_seed_roles_permissions.php',
    'source_errors' => $sourceErrors,
    'database_errors' => $databaseErrors,
    'checks_passed' => $ok,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "verifier: verify_platform_permission_catalog\n";
    echo 'checks_passed: ' . ($ok ? 'true' : 'false') . "\n";
    foreach ($errors as $e) {
        echo "ERROR: {$e}\n";
    }
}

exit($ok ? 0 : 1);
