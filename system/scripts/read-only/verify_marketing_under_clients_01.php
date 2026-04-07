<?php

/**
 * Read-only verifier: STORY-03.6.7 — Marketing out of primary nav; entry under CLIENTS workspace
 *
 * Run: php system/scripts/read-only/verify_marketing_under_clients_01.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function m37(string $label, string $file, string $needle, bool $want = true): void
{
    global $pass, $fail, $checks;
    if (!is_file($file)) {
        $checks[] = ['FAIL', $label, 'missing file'];
        $fail++;

        return;
    }
    $ok = str_contains((string) file_get_contents($file), $needle) === $want;
    if ($ok) {
        $checks[] = ['PASS', $label, ''];
        $pass++;
    } else {
        $checks[] = ['FAIL', $label, ($want ? 'expected: ' : 'must not contain: ') . substr($needle, 0, 96)];
        $fail++;
    }
}

$base = dirname(__DIR__, 2);
$nav = $base . '/shared/layout/base.php';
$ws = $base . '/modules/clients/views/partials/clients-workspace-data.php';

m37('M1: no primary Marketing home tuple in navItems', $nav, "'/marketing/campaigns', 'Marketing'", false);
m37('M2: Clients nav active covers /marketing prefix', $nav, "str_starts_with(\$navPath, '/marketing')");
m37('M3: former Marketing primary-nav icon (bell) removed from navSideIcons', $nav, 'M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9 M13 13h3a5 5 0 0 0 5-5v-1', false);
m37('M4: clients workspace exposes Marketing tab href', $ws, "'/marketing/campaigns'");
m37('M5: Marketing tab gated by marketing.view', $ws, "'marketing.view'");
m37('M6: Marketing tab id stable', $ws, "'id' => 'marketing'");

echo "\nVERIFIER: verify_marketing_under_clients_01\n";
echo str_repeat('─', 72) . "\n";
foreach ($checks as [$s, $l, $d]) {
    echo sprintf("  [%s] %s%s\n", $s, $l, $d !== '' ? "\n         → $d" : '');
}
echo str_repeat('─', 72) . "\n";
echo sprintf("  PASSED: %d   FAILED: %d   TOTAL: %d\n\n", $pass, $fail, $pass + $fail);
if ($fail > 0) {
    echo "STATUS: FAIL\n\n";
    exit(1);
}
echo "STATUS: PASS\n\n";
exit(0);
