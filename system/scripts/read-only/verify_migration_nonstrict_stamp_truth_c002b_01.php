<?php

declare(strict_types=1);

/**
 * C-002B-NONSTRICT-MIGRATION-STAMP-TRUTH-RECOVERY-01: static proof that non-strict migrate no longer
 * stamps after blanket follow-up error swallowing, and requires end-state proof when legacy conflicts
 * were tolerated. No database.
 *
 * Usage:
 *   php system/scripts/read-only/verify_migration_nonstrict_stamp_truth_c002b_01.php
 */

$base = dirname(__DIR__, 2);
$migrate = $base . '/scripts/migrate.php';
$verify = $base . '/scripts/migrate_end_state_verify.php';

foreach (['migrate' => $migrate, 'verify' => $verify] as $label => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: missing {$label}: {$path}\n");
        exit(1);
    }
}

$m = (string) file_get_contents($migrate);
$v = (string) file_get_contents($verify);

$checks = [
    'migrate.php requires migrate_end_state_verify.php' => str_contains($m, "require __DIR__ . '/migrate_end_state_verify.php'"),
    'migrate.php: no blanket Legacy follow-up tolerance' => !str_contains($m, 'Legacy follow-up SQL error tolerated'),
    'migrate.php: proof gate before stamp when hadToleratedLegacyError' => str_contains($m, 'migration_nonstrict_end_state_proof_passes')
        && str_contains($m, 'hadToleratedLegacyError')
        && str_contains($m, 'NOT stamped'),
    'migrate_end_state_verify.php: information_schema proof' => str_contains($v, 'information_schema.COLUMNS')
        && str_contains($v, 'information_schema.TABLES'),
    'migrate_end_state_verify.php: empty requirements => false' => str_contains($v, 'return false')
        && preg_match('/\$req\[\'tables\'\]\s*===\s*\[\][\s\S]*return false/s', $v) === 1,
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'MISSING') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
