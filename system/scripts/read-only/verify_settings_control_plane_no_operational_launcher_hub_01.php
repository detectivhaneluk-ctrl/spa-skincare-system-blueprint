<?php

/**
 * Read-only verifier: SETTINGS-CONTROL-PLANE-NO-OPERATIONAL-LAUNCHER-HUB-01
 *
 * BUSINESS-IA Phase 4 — Settings/Admin must not duplicate top-nav operational homes.
 *
 * Run: php system/scripts/read-only/verify_settings_control_plane_no_operational_launcher_hub_01.php
 * Expected exit code: 0
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function sc_assert(string $label, string $file, string $needle, bool $shouldContain = true): void
{
    global $pass, $fail, $checks;
    if (!is_file($file)) {
        $checks[] = ['FAIL', $label, "File not found: $file"];
        $fail++;
        return;
    }
    $found = str_contains((string) file_get_contents($file), $needle);
    if ($found === $shouldContain) {
        $checks[] = ['PASS', $label, ''];
        $pass++;
    } else {
        $checks[] = ['FAIL', $label, ($shouldContain ? 'missing: ' : 'must not contain: ') . substr($needle, 0, 96)];
        $fail++;
    }
}

$base = dirname(__DIR__, 2);
$shell = $base . '/modules/settings/views/partials/shell.php';
$index = $base . '/modules/settings/views/index.php';
$pay = $base . '/modules/settings/views/partials/payment-settings.php';

sc_assert('S1: shell has no settings-operational-areas block', $shell, 'settings-operational-areas', false);
sc_assert('S2: shell has no Shortcuts to other workspaces copy', $shell, 'Shortcuts to other workspaces', false);
sc_assert('S3: shell has no outbound /reports link', $shell, 'href="/reports"', false);
sc_assert('S4: shell has no outbound /staff link', $shell, 'href="/staff"', false);
sc_assert('S5: index membership section: no catalog/client membership hrefs', $index, 'href="/memberships"', false);
sc_assert('S6: payment-settings: no operational gift-cards/packages/refund hrefs', $pay, 'href="/gift-cards"', false);
sc_assert('S7: payment-settings: no /packages href', $pay, 'href="/packages"', false);
sc_assert('S8: payment-settings: no refund-review href', $pay, 'href="/memberships/refund-review"', false);
sc_assert('S9: shell still exposes editable-settings sidebar', $shell, 'Editable settings');
sc_assert('S10: index still renders public_channels cards (contract)', $index, 'Public Commerce</h3>');

echo "\nVERIFIER: verify_settings_control_plane_no_operational_launcher_hub_01\n";
echo str_repeat('─', 72) . "\n";
foreach ($checks as [$status, $label, $detail]) {
    echo sprintf("  [%s] %s%s\n", $status, $label, $detail !== '' ? "\n         → $detail" : '');
}
echo str_repeat('─', 72) . "\n";
echo sprintf("  PASSED: %d   FAILED: %d   TOTAL: %d\n\n", $pass, $fail, $pass + $fail);

if ($fail > 0) {
    echo "STATUS: FAIL\n\n";
    exit(1);
}
echo "STATUS: PASS\n\n";
exit(0);
