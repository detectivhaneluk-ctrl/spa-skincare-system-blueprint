<?php

declare(strict_types=1);

/**
 * Migration baseline report: compares data/migrations/*.sql on disk to rows in `migrations`.
 * Read-only; does not apply DDL. Surfaces pending migrations and orphan stamps for ops visibility.
 *
 * Usage (from repo root, requires DB env like the app):
 *   php system/scripts/read-only/verify_migration_baseline_readonly.php
 *   php system/scripts/read-only/verify_migration_baseline_readonly.php --json
 *   php system/scripts/read-only/verify_migration_baseline_readonly.php --strict
 *
 * --strict: exit 1 when baseline is not fully aligned — pending migrations, orphan stamps (DB row with no
 * matching file), or missing `migrations` table. (Stricter than historical behavior, which only failed on pending.)
 *
 * Machine-readable: use --json; fields include `baseline_aligned`, `issues`, `strict_would_fail`.
 *
 * Canonical deploy entrypoint (delegates here with `--strict`): `system/scripts/run_migration_baseline_deploy_gate_01.php` (FND-MIG-02).
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Core\App\MigrationBaseline;

$json = in_array('--json', $argv, true);
$strict = in_array('--strict', $argv, true);

$pdo = app(\Core\App\Database::class)->connection();
$report = MigrationBaseline::collect($systemPath, $pdo);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'Migration baseline report' . PHP_EOL;
    echo '  migrations dir: ' . $report['migrations_dir'] . PHP_EOL;
    echo '  SQL files on disk: ' . $report['files_on_disk'] . PHP_EOL;
    echo '  applied rows: ' . $report['rows_in_migrations_table'] . PHP_EOL;
    echo '  migrations table present: ' . ($report['migrations_table_missing'] ? 'no' : 'yes') . PHP_EOL;
    echo '  baseline_aligned: ' . ($report['baseline_aligned'] ? 'yes' : 'no') . PHP_EOL;
    echo '  pending (on disk, not stamped): ' . $report['pending_count'] . PHP_EOL;
    $pending = $report['pending'];
    if ($pending !== []) {
        foreach (array_slice($pending, 0, 25) as $p) {
            echo '    - ' . $p . PHP_EOL;
        }
        if (count($pending) > 25) {
            echo '    ... +' . (count($pending) - 25) . ' more' . PHP_EOL;
        }
    }
    echo '  orphan stamps (in DB, file missing): ' . $report['orphan_stamp_count'] . PHP_EOL;
    if ($report['orphan_stamps'] !== []) {
        foreach ($report['orphan_stamps'] as $o) {
            echo '    - ' . $o . PHP_EOL;
        }
    }
    echo '  latest file (sort order): ' . ($report['latest_file'] ?? '(none)') . PHP_EOL;
    echo '  latest applied row: ' . ($report['latest_applied'] ?? '(none)') . PHP_EOL;
    if ($report['issues'] !== []) {
        echo '  issues: ' . implode(', ', $report['issues']) . PHP_EOL;
    }
    echo PHP_EOL;
    echo 'Deploy gate (verify-only): php system/scripts/run_migration_baseline_deploy_gate_01.php' . PHP_EOL;
    echo 'Apply + baseline gate: php system/scripts/run_migration_baseline_deploy_gate_01.php --apply [--strict]' . PHP_EOL;
    echo 'Or direct: php system/scripts/migrate.php [--strict] --verify-baseline' . PHP_EOL;
    echo 'HTTP enforcement (503 when misaligned): set MIGRATION_BASELINE_ENFORCE=true' . PHP_EOL;
}

$exit = 0;
if ($strict && !$report['baseline_aligned']) {
    $exit = 1;
    if (!$json) {
        fwrite(STDERR, PHP_EOL . 'STRICT: migration baseline not aligned — ' . MigrationBaseline::strictSummaryLine($report) . ' — exit 1.' . PHP_EOL);
    }
}

exit($exit);
