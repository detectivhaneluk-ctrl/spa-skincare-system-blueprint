<?php

/**
 * Read-only verifier: CLIENTS-OWNED-VALUE-PROFILE-PHASE6-01
 *
 * Client profile must render package + gift-card read-model data (not dead).
 * Membership summary + invoice balance surfaced on profile when read model supports it (Phase 5.1).
 *
 * Run: php system/scripts/read-only/verify_clients_owned_value_profile_phase6_01.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function cv6(string $label, string $file, string $needle, bool $want = true): void
{
    global $pass, $fail, $checks;
    if (!is_file($file)) {
        $checks[] = ['FAIL', $label, "missing file"];
        $fail++;
        return;
    }
    $ok = str_contains((string) file_get_contents($file), $needle) === $want;
    if ($ok) {
        $checks[] = ['PASS', $label, ''];
        $pass++;
    } else {
        $checks[] = ['FAIL', $label, ($want ? 'expected: ' : 'must not contain: ') . substr($needle, 0, 80)];
        $fail++;
    }
}

$base = dirname(__DIR__, 2);
$show = $base . '/modules/clients/views/show.php';
$read = $base . '/modules/clients/services/ClientProfileReadService.php';
$ctrl = $base . '/modules/clients/controllers/ClientController.php';

cv6('V1: show has Owned value section anchor', $show, 'id="client-ref-owned-value"');
cv6('V2: show renders package summary keys', $show, "\$ps['total']");
cv6('V3: show renders recent package rows with client-held link', $show, '/packages/client-packages/');
cv6('V4: show renders gift card summary', $show, "\$gs['total_balance']");
cv6('V5: show renders recent gift card rows', $show, '/gift-cards/');
cv6('V6: show surfaces membership summary keys', $show, "\$ms['total']");
cv6('V6b: show surfaces invoice balance due from sales summary', $show, "\$ss['total_due']");
cv6('V6c: owned section title obligations', $show, 'Owned value &amp; obligations');
cv6('V7: read service still composes packages bucket', $read, "'packages' =>");
cv6('V8: read service still composes gift_cards bucket', $read, "'gift_cards' =>");
cv6('V8b: read service composes memberships bucket', $read, "'memberships' =>");
cv6('V9: controller show still extracts packageSummary for view', $ctrl, '$packageSummary = $read[\'packages\'][\'summary\']');
cv6('V10: controller show still extracts giftCardSummary for view', $ctrl, '$giftCardSummary = $read[\'gift_cards\'][\'summary\']');
cv6('V11: controller show extracts membership summary for view', $ctrl, '$membershipSummary = $read[\'memberships\'][\'summary\']');

echo "\nVERIFIER: verify_clients_owned_value_profile_phase6_01\n";
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
