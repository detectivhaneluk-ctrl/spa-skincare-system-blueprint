<?php
/**
 * Read-only verifier: PACKAGE-OWNERSHIP-IA-PHASE3-01
 * Plan definitions → Catalog; client-held /packages/client-packages* → Clients; /sales + /gift-cards → Sales.
 *
 * Run: php system/scripts/read-only/verify_package_ownership_ia_phase3_01.php
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function pchk(string $label, string $file, string $needle, bool $want = true): void
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
        $checks[] = ['FAIL', $label, ($want ? 'expected: ' : 'expected absent: ') . substr($needle, 0, 96)];
        $fail++;
    }
}

$base = dirname(__DIR__, 2);
$navB = $base . '/shared/layout/base.php';
$hub = $base . '/modules/services-resources/views/index.php';
$shell = $base . '/modules/settings/views/partials/shell.php';
$clIdx = $base . '/modules/clients/views/index.php';
$salesShell = $base . '/modules/sales/views/partials/sales-workspace-shell.php';
$pkgDef = $base . '/modules/packages/views/definitions/index.php';

pchk('P1: navIsClientsPackages present', $navB, '$navIsClientsPackages');
pchk('P2: Clients nav ties client-packages prefix', $navB, '$navIsClientsMemberships || $navIsClientsPackages');
pchk('P3: Catalog includes /packages plans excluding client-packages', $navB, "str_starts_with(\$navPath, '/packages')\n            && ! \$navIsClientsPackages");
pchk('P4: navIsSales has no /packages line', $navB, "str_starts_with(\$navPath, '/packages');", false);
pchk('P5: catalog hub does not link client-packages as hub secondary', $hub, 'href="/packages/client-packages"', false);
pchk('P6: catalog hub links Sales for checkout', $hub, 'href="/sales"');
pchk('P7: catalog hub links Clients for held-package discovery', $hub, 'href="/clients"');
pchk('P8: catalog hub still links plan list', $hub, 'href="/packages"');
pchk('P9: settings packages card names Catalog + Clients + Sales', $shell, 'Package plans (Catalog)');
pchk('P10: clients list toolbar links client packages', $clIdx, 'href="/packages/client-packages"');
pchk('P11: sales workspace shell supports title override', $salesShell, '$salesWorkspaceShellTitle');
pchk('P12: package definitions view sets Catalog-oriented shell title', $pkgDef, '$salesWorkspaceShellTitle = \'Package plans\'');

echo "\nVERIFIER: verify_package_ownership_ia_phase3_01\n";
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
