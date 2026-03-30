<?php

declare(strict_types=1);

/**
 * SQL-IDENTIFIER-SAFETY-GUARD-01 — static guard for dynamic SQL identifier hardening.
 *
 * - {@see \Core\App\SqlIdentifier} validates + backticks table/column segments for {@see \Core\App\Database::insert()}.
 * - Client appointment profile list uses fixed column branches (no request-derived identifier fragments in WHERE/ORDER).
 *
 * From repo root:
 *   php system/scripts/read-only/verify_sql_identifier_safety_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$dbPath = $system . '/core/app/Database.php';
$sqlIdPath = $system . '/core/app/SqlIdentifier.php';
$profilePath = $system . '/modules/appointments/providers/ClientAppointmentProfileProviderImpl.php';

$db = is_file($dbPath) ? (string) file_get_contents($dbPath) : '';
$sqlId = is_file($sqlIdPath) ? (string) file_get_contents($sqlIdPath) : '';
$profile = is_file($profilePath) ? (string) file_get_contents($profilePath) : '';

$checks = [];

$checks['SqlIdentifier.php exists with quoteTable/quoteColumn/assertSegment'] = $sqlId !== ''
    && str_contains($sqlId, 'final class SqlIdentifier')
    && str_contains($sqlId, 'function quoteTable')
    && str_contains($sqlId, 'function quoteColumn')
    && str_contains($sqlId, 'function assertSegment');

$checks['Database::insert uses SqlIdentifier for table and columns'] = str_contains($db, 'SqlIdentifier::quoteTable')
    && str_contains($db, 'SqlIdentifier::quoteColumn')
    && str_contains($db, 'insert() column names must be string keys');

$checks['Database::insert rejects empty data array'] = str_contains($db, 'non-empty data');

$checks['ClientAppointmentProfileProviderImpl: removed dateCol/orderCol request-adjacent SQL fragments'] =
    $profile !== ''
    && !str_contains($profile, '$dateCol')
    && !str_contains($profile, '$orderCol')
    && str_contains($profile, 'AND a.created_at >=')
    && str_contains($profile, 'AND a.start_at >=')
    && str_contains($profile, '$orderBySql');

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

// Heuristic scan: dynamic ORDER BY / INSERT INTO variable interpolation in modules (excluding vendor)
$modulesDir = $system . '/modules';
$risky = [];
if (is_dir($modulesDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modulesDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $p = $file->getPathname();
        $s = (string) file_get_contents($p);
        if (preg_match('/ORDER BY\s*\{\s*\$/', $s) === 1) {
            $risky[] = str_replace(['\\', '/'], '/', substr($p, strlen($system) + 1));
        }
        if (preg_match('/INSERT INTO\s*\{\s*\$/', $s) === 1) {
            $risky[] = str_replace(['\\', '/'], '/', substr($p, strlen($system) + 1));
        }
    }
}
$risky = array_values(array_unique($risky));
echo PHP_EOL . 'Heuristic: files with ORDER BY {$ or INSERT INTO {$ in modules: ' . count($risky) . PHP_EOL;
foreach ($risky as $r) {
    echo '  - ' . $r . PHP_EOL;
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'SQL identifier safety static checks passed.' . PHP_EOL;
exit(0);
