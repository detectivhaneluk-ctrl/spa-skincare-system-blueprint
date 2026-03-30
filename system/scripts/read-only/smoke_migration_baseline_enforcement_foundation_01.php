<?php

declare(strict_types=1);

/**
 * FOUNDATION-MIGRATION-BASELINE-ENFORCEMENT-HARDENING-01 — minimal proof for baseline reporting + strict CLI contract.
 *
 * Requires DB (same as app). Does not mutate schema.
 *
 *   php system/scripts/read-only/smoke_migration_baseline_enforcement_foundation_01.php
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Core\App\MigrationBaseline;

$passed = 0;
$failed = 0;
$pass = static function (string $name) use (&$passed): void {
    $passed++;
    echo "PASS  {$name}\n";
};
$fail = static function (string $name, string $detail) use (&$failed): void {
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
};

$pdo = app(\Core\App\Database::class)->connection();
$report = MigrationBaseline::collect($systemPath, $pdo);

$required = [
    'migrations_dir', 'files_on_disk', 'rows_in_migrations_table', 'pending', 'pending_count',
    'orphan_stamps', 'orphan_stamp_count', 'migrations_table_missing', 'latest_file', 'latest_applied',
    'baseline_aligned', 'issues', 'strict_would_fail',
];
foreach ($required as $k) {
    if (!array_key_exists($k, $report)) {
        $fail('report_has_key_' . $k, 'missing key');
        echo "\nSummary: {$passed} passed, {$failed} failed.\n";
        exit(1);
    }
}
$pass('migration_baseline_report_schema_complete');

$expectedAligned = !$report['migrations_table_missing'] && $report['pending_count'] === 0 && $report['orphan_stamp_count'] === 0;
$report['baseline_aligned'] === $expectedAligned && $report['strict_would_fail'] === !$expectedAligned
    ? $pass('baseline_aligned_matches_computed_flags')
    : $fail('baseline_aligned_matches_computed_flags', json_encode([
        'baseline_aligned' => $report['baseline_aligned'],
        'strict_would_fail' => $report['strict_would_fail'],
        'expected_aligned' => $expectedAligned,
    ]));

$php = PHP_BINARY;
$verify = $systemPath . '/scripts/read-only/verify_migration_baseline_readonly.php';
exec(escapeshellarg($php) . ' ' . escapeshellarg($verify) . ' --json 2>&1', $out, $code);
$json = json_decode(implode("\n", $out), true);
if (!is_array($json) || !isset($json['baseline_aligned'], $json['issues'])) {
    $fail('verify_script_json_parse', implode("\n", array_slice($out, 0, 5)));
} else {
    $pass('verify_migration_baseline_readonly_json_ok');
}

$strictCode = -1;
exec(escapeshellarg($php) . ' ' . escapeshellarg($verify) . ' --strict 2>&1', $outStrict, $strictCode);
$aligned = (bool) ($json['baseline_aligned'] ?? false);
if ($aligned && $strictCode !== 0) {
    $fail('strict_exit_when_aligned', 'expected 0, got ' . $strictCode);
} elseif (!$aligned && $strictCode !== 1) {
    $fail('strict_exit_when_misaligned', 'expected 1, got ' . $strictCode);
} else {
    $pass('strict_exit_code_contract');
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
