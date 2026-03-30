<?php

declare(strict_types=1);

/**
 * FND-MIG-02 — Canonical migration baseline deploy gate.
 *
 * **Aligned** = `migrations` table exists, every `system/data/migrations/*.sql` has a stamp, and every stamp has a
 * matching file on disk (see `Core\App\MigrationBaseline::collect`). This is disk ↔ stamp truth only, not a full
 * semantic diff to `full_project_schema.sql`.
 *
 * **Verify-only (read-only, fail-closed):** exit 1 if not aligned. Same behavior as
 * `verify_migration_baseline_readonly.php --strict`.
 *   php system/scripts/run_migration_baseline_deploy_gate_01.php
 *
 * **Apply migrations + baseline check:** runs `migrate.php` with `--verify-baseline` always injected (deploy-safe exit).
 *   php system/scripts/run_migration_baseline_deploy_gate_01.php --apply
 *   php system/scripts/run_migration_baseline_deploy_gate_01.php --apply --strict
 *
 * From repo root; requires app DB env (same as other system scripts).
 */

$systemBase = dirname(__DIR__);
$php = PHP_BINARY;
$verifyScript = $systemBase . '/scripts/read-only/verify_migration_baseline_readonly.php';
$migrateScript = $systemBase . '/scripts/migrate.php';

$argv = array_slice($_SERVER['argv'] ?? [], 1);
$applyIdx = array_search('--apply', $argv, true);

if ($applyIdx !== false) {
    $forward = array_values(array_merge(
        array_slice($argv, 0, $applyIdx),
        array_slice($argv, $applyIdx + 1)
    ));
    if (!in_array('--verify-baseline', $forward, true)) {
        $forward[] = '--verify-baseline';
    }
    $parts = [escapeshellarg($php), escapeshellarg($migrateScript)];
    foreach ($forward as $a) {
        $parts[] = escapeshellarg($a);
    }
    passthru(implode(' ', $parts), $code);
    exit($code);
}

passthru(escapeshellarg($php) . ' ' . escapeshellarg($verifyScript) . ' --strict', $code);
exit($code);
