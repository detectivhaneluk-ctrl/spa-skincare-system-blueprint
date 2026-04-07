<?php

/**
 * Read-only verifier: STORY-03.2.11 — client profile deep links to held surfaces
 *
 * Proves client profile (show.php) exposes list destinations with the same
 * client_id query contract as ClientMembershipController, ClientPackageController,
 * and GiftCardController index actions.
 *
 * Run: php system/scripts/read-only/verify_story_03_2_11_client_profile_deep_links_01.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function dl11(string $label, string $file, string $needle, bool $want = true): void
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
        $checks[] = ['FAIL', $label, ($want ? 'expected: ' : 'must not contain: ') . substr($needle, 0, 100)];
        $fail++;
    }
}

$base = dirname(__DIR__, 2);
$show = $base . '/modules/clients/views/show.php';
$memCtrl = $base . '/modules/memberships/controllers/ClientMembershipController.php';
$pkgCtrl = $base . '/modules/packages/controllers/ClientPackageController.php';
$gcCtrl = $base . '/modules/gift-cards/controllers/GiftCardController.php';

dl11('P1: profile links packages with client_id', $show, '/packages/client-packages?client_id=<?= $clientId ?>');
dl11('P2: profile links gift cards with client_id', $show, '/gift-cards?client_id=<?= $clientId ?>');
dl11('P3: profile links memberships with client_id', $show, '/memberships/client-memberships?client_id=<?= $clientId ?>');
dl11('P4: profile must not use name-only gift-card filter in view-all link', $show, '/gift-cards?client_name=', false);
dl11('P5: profile must not use search-only packages deep link', $show, '/packages/client-packages?search=', false);
dl11('P6: profile must not use search-only memberships deep link', $show, '/memberships/client-memberships?search=', false);
dl11('C1: ClientMembershipController reads client_id from GET', $memCtrl, "\$_GET['client_id']");
dl11('C2: ClientPackageController reads client_id from GET', $pkgCtrl, "\$_GET['client_id']");
dl11('C3: GiftCardController reads client_id from GET', $gcCtrl, "\$_GET['client_id']");

echo "\nVERIFIER: verify_story_03_2_11_client_profile_deep_links_01\n";
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
