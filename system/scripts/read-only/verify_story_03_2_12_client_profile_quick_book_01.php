<?php

/**
 * Read-only verifier: STORY-03.2.12 — client profile Quick Book (always visible)
 *
 * Proves summary profile exposes Quick Book and that full-page booking prefill
 * carries client_id into the appointment wizard session.
 *
 * Run: php system/scripts/read-only/verify_story_03_2_12_client_profile_quick_book_01.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$checks = [];

function qb12(string $label, string $file, string $needle, bool $want = true): void
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
$read = $base . '/modules/clients/services/ClientProfileReadService.php';
$apptCtrl = $base . '/modules/appointments/controllers/AppointmentController.php';
$wizCtrl = $base . '/modules/appointments/controllers/AppointmentWizardController.php';
$wizState = $base . '/modules/appointments/services/AppointmentWizardStateService.php';

qb12('P1: profile has Quick Book region', $show, 'client-ref-quick-book');
qb12('P2: profile Quick Book label', $show, '>Quick Book<');
qb12('P3: Quick Book uses live add URL from read model', $show, 'htmlspecialchars($resumeAddAppointmentUrl)');
qb12('P4: permissionless state still shows Quick Book (disabled)', $show, 'client-ref-quick-book__disabled');
qb12('R1: read model builds /appointments/create?client_id=', $read, "'/appointments/create?client_id='");
qb12('C1: create→wizard redirect preserves client_id', $apptCtrl, "\$q['client_id']");
qb12('C2: create→wizard redirect carries resolved branch when GET omits it', $apptCtrl, "\$q['branch_id'] = \$branchId");
qb12('W1: wizard init seeds client_id from prefill GET', $wizState, "\$prefill['client_id']");
qb12('W2: wizard entry merges client_id into existing session state', $wizCtrl, "\$_GET['client_id']");

echo "\nVERIFIER: verify_story_03_2_12_client_profile_quick_book_01\n";
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
