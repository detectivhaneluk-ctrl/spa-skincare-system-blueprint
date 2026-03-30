<?php

declare(strict_types=1);

/**
 * C-001-CANONICAL-SCHEMA-TRUTH-RECOVERY-01: proves canonical snapshot includes marketing
 * automations + special-offers end-state so migrate.php --canonical stamping is not a lie.
 *
 * No database required.
 *
 * Usage (from repo root or any cwd):
 *   php system/scripts/read-only/verify_canonical_schema_marketing_c001_01.php
 */

$base = dirname(__DIR__, 2);
$path = $base . '/data/full_project_schema.sql';

if (!is_file($path)) {
    fwrite(STDERR, "FAIL: canonical schema not found: {$path}\n");
    exit(1);
}

$sql = (string) file_get_contents($path);

$checks = [
    'CREATE TABLE marketing_automations' => str_contains($sql, 'CREATE TABLE marketing_automations'),
    'marketing_automations.uq_marketing_automation_branch_key' => str_contains($sql, 'uq_marketing_automation_branch_key'),
    'CREATE TABLE marketing_special_offers' => str_contains($sql, 'CREATE TABLE marketing_special_offers'),
    'marketing_special_offers.offer_option' => preg_match('/CREATE TABLE marketing_special_offers\s*\([^;]*\boffer_option\b/s', $sql) === 1,
    'marketing_special_offers.start_date' => preg_match('/CREATE TABLE marketing_special_offers\s*\([^;]*\bstart_date\b/s', $sql) === 1,
    'marketing_special_offers.end_date' => preg_match('/CREATE TABLE marketing_special_offers\s*\([^;]*\bend_date\b/s', $sql) === 1,
    'idx_mkt_special_offers_active_window' => str_contains($sql, 'idx_mkt_special_offers_active_window'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'MISSING') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
