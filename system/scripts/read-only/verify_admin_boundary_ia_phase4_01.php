<?php
/**
 * Read-only verifier: ADMIN-BOUNDARY-IA-PHASE4-01
 * Admin shell reads as policy/controls/defaults; cross-links name canonical homes.
 *
 * Run: php system/scripts/read-only/verify_admin_boundary_ia_phase4_01.php
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function achk(string $label, string $file, string $needle, bool $want = true): void
{
    global $pass, $fail, $checks;
    if (!file_exists($file)) {
        $checks[] = ['FAIL', $label, "File not found: $file"];
        $fail++;
        return;
    }
    $found = str_contains(file_get_contents($file), $needle);
    if ($found === $want) {
        $checks[] = ['PASS', $label, ''];
        $pass++;
    } else {
        $checks[] = ['FAIL', $label, ($want ? 'expected: ' : 'expected absent: ') . substr($needle, 0, 100)];
        $fail++;
    }
}

$base = dirname(__DIR__, 2);
$shell = $base . '/modules/settings/views/partials/shell.php';
$side = $base . '/modules/settings/Support/SettingsShellSidebar.php';
$idx = $base . '/modules/settings/views/index.php';

achk('A1: shell default subtitle mentions policies/controls/defaults', $shell, 'Policies, controls, and defaults');
achk('A2: cross-links section title not "Manage operational areas"', $shell, 'Manage operational areas', false);
achk('A3: cross-links section is shortcuts framing', $shell, 'Shortcuts to other workspaces');
achk('A4: payroll link labeled Team home', $shell, 'Payroll runs (Team)');
achk('A5: client packages link labeled Clients', $shell, 'Client packages (Clients)');
achk('A6: client memberships link labeled Clients', $shell, 'Active client memberships (Clients)');
achk('A7: Reports cross-link with Reports home label', $shell, 'Reports home (Reports)');
achk('A8: Reports href present', $shell, 'href="/reports"');
achk('A9: Sales shortcuts card when permitted', $shell, 'Checkout (Sales)');
achk('A10: Invoices Sales cross-link', $shell, 'Invoices (Sales)');
achk('A11: Gift cards Sales cross-link', $shell, 'Gift cards (Sales)');
achk('A12: branches card clarifies Admin registry', $shell, 'Branches registry (Admin)');
achk('A13: SettingsShellSidebar exposes canViewSalesLink', $side, "'canViewSalesLink'");
achk('A14: main settings index subtitle aligns with boundary', $idx, 'Policies, controls, and defaults');

echo "\nVERIFIER: verify_admin_boundary_ia_phase4_01\n";
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
