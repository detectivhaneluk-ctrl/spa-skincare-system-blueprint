<?php
/**
 * Read-only verifier: MEMBERSHIP-OWNERSHIP-IA-PHASE2-01
 * Plan definitions → Catalog; client-held records → Clients; refund-review + settings → Admin.
 *
 * Run: php system/scripts/read-only/verify_membership_ownership_ia_phase2_01.php
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function mchk(string $label, string $file, string $needle, bool $want = true): void
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

mchk('M1: navIsClientsMemberships present', $navB, '$navIsClientsMemberships');
mchk('M2: Clients nav ties client-memberships prefix', $navB, "str_starts_with(\$navPath, '/clients') || \$navIsClientsMemberships");
mchk('M3: Catalog includes membership plan paths excluding client-memberships', $navB, "str_starts_with(\$navPath, '/memberships')\n            && ! \$navIsClientsMemberships");
mchk('M4: refund-review grouped under Admin highlight', $navB, "str_starts_with(\$navPath, '/memberships/refund-review')");
mchk('M5: settingsActivePrefixes has only settings + branches', $navB, "'/settings',\n        '/branches',");
mchk('M6: catalog hub does not link client-memberships as secondary hub card', $hub, 'href="/memberships/client-memberships"', false);
mchk('M7: catalog hub links Clients for held-membership discovery', $hub, 'href="/clients"');
mchk('M8: catalog hub still links plan list', $hub, 'href="/memberships"');
mchk('M9: settings shell names Catalog + Clients for membership shortcuts', $shell, 'Membership plans (Catalog)');
mchk('M10: clients list toolbar links active memberships', $clIdx, 'href="/memberships/client-memberships"');

echo "\nVERIFIER: verify_membership_ownership_ia_phase2_01\n";
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
