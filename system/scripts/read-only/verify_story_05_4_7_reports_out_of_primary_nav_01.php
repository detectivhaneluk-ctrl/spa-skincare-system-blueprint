<?php

/**
 * Read-only verifier: STORY-05.4.7 — Reports out of primary nav; entry under CASHIER (Sales workspace) and HOME (dashboard quick access)
 *
 * Run: php system/scripts/read-only/verify_story_05_4_7_reports_out_of_primary_nav_01.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function s547(string $label, string $file, string $needle, bool $want = true): void
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
$salesShell = $base . '/modules/sales/views/partials/sales-workspace-shell.php';
$dashSvc = $base . '/modules/dashboard/services/TenantOperatorDashboardService.php';

s547('R1: no primary Reports home tuple in navItems', $nav, "'/reports', 'Reports'", false);
s547('R2: navIsReports still defined for /reports active family', $nav, '$navIsReports');
s547('R3: Overview primary tab active on /reports', $nav, "str_starts_with(\$navPath, '/dashboard') || \$navIsReports");
s547('R4: CASHIER shell exposes Reports tab href', $salesShell, "'url' => '/reports'");
s547('R5: Reports tab gated by reports.view', $salesShell, 'reports.view');
s547('R6: dashboard quick links add Reports when permitted', $dashSvc, 'reports.view');
s547('R7: dashboard quick link targets live /reports', $dashSvc, "'href' => '/reports'");

echo "\nVERIFIER: verify_story_05_4_7_reports_out_of_primary_nav_01\n";
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
