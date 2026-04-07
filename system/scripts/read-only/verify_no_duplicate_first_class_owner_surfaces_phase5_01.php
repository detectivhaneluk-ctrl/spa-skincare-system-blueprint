<?php

/**
 * Read-only verifier: NO-DUPLICATE-FIRST-CLASS-OWNER-SURFACES-PHASE5-01
 *
 * BUSINESS-IA Phase 5 — Plan definition workspaces must not launch client-held surfaces;
 * Sales shell must not tab to Catalog-owned package plan routes.
 *
 * Run: php system/scripts/read-only/verify_no_duplicate_first_class_owner_surfaces_phase5_01.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function d5_assert(string $label, string $file, string $needle, bool $want = true): void
{
    global $pass, $fail, $checks;
    if (!is_file($file)) {
        $checks[] = ['FAIL', $label, "File not found: $file"];
        $fail++;
        return;
    }
    $found = str_contains((string) file_get_contents($file), $needle);
    if ($found === $want) {
        $checks[] = ['PASS', $label, ''];
        $pass++;
    } else {
        $checks[] = ['FAIL', $label, ($want ? 'missing: ' : 'must not contain: ') . substr($needle, 0, 88)];
        $fail++;
    }
}

$base = dirname(__DIR__, 2);
$pkgDef = $base . '/modules/packages/views/definitions/index.php';
$memDef = $base . '/modules/memberships/views/definitions/index.php';
$salesShell = $base . '/modules/sales/views/partials/sales-workspace-shell.php';
$hub = $base . '/modules/services-resources/views/index.php';
$pkgCli = $base . '/modules/packages/views/client-packages/index.php';
$memCli = $base . '/modules/memberships/views/client-memberships/index.php';

d5_assert('D1: package plan index has no href to client-packages', $pkgDef, 'href="/packages/client-packages"', false);
d5_assert('D2: package plan index has no "Client packages" btn row', $pkgDef, '>Client packages</a>', false);
d5_assert('D3: membership plan index has no href to client-memberships', $memDef, 'href="/memberships/client-memberships"', false);
d5_assert('D4: membership plan index has no Active client memberships btn', $memDef, '>Active client memberships</a>', false);
d5_assert('D5: sales shell tabs omit /packages (Catalog owns plan CRUD)', $salesShell, "'url' => '/packages'", false);
d5_assert('D6: sales shell still has gift cards + register tabs', $salesShell, "'url' => '/gift-cards'");
d5_assert('D7: catalog hub still links package + membership plan lists', $hub, 'href="/packages"');
d5_assert('D8: catalog hub still links /memberships plans', $hub, 'href="/memberships"');
d5_assert('D9: client packages screen still links back to plan list', $pkgCli, '← Package plans');
d5_assert('D10: client memberships screen still links back to plan list', $memCli, '← Membership plans');

echo "\nVERIFIER: verify_no_duplicate_first_class_owner_surfaces_phase5_01\n";
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
