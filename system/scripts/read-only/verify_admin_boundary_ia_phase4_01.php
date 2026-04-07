<?php
/**
 * Read-only verifier: ADMIN-BOUNDARY-IA-PHASE4-01 (control-plane honesty)
 * Admin shell is policy/controls/defaults only — no duplicated operational launcher hub.
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
$pay = $base . '/modules/settings/views/partials/payment-settings.php';

achk('A1: shell default subtitle mentions policies/controls/defaults', $shell, 'Policies, controls, and defaults');
achk('A2: cross-links section title not "Manage operational areas"', $shell, 'Manage operational areas', false);
achk('A3: no "Shortcuts to other workspaces" launcher block', $shell, 'Shortcuts to other workspaces', false);
achk('A4: no operational grid class in shell', $shell, 'settings-operational-areas', false);
achk('A5: main settings index subtitle aligns with control-plane', $idx, 'Policies, controls, and defaults');
achk('A6: membership defaults help has no /memberships href', $idx, 'href="/memberships"', false);
achk('A7: payment-settings has no gift-cards launcher', $pay, 'href="/gift-cards"', false);
achk('A8: payment-settings has no packages launcher', $pay, 'href="/packages"', false);
achk('A9: payment-settings has no refund-review launcher', $pay, 'href="/memberships/refund-review"', false);
achk('A10: SettingsShellSidebar still exposes permission flags for controller', $side, "'canViewSalesLink'");

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
