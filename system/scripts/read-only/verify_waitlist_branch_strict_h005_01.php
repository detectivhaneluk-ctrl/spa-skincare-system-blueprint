<?php

declare(strict_types=1);

/**
 * H-005: branch-filtered waitlist list/count and related branch-scoped predicates must not OR-in NULL branch_id rows.
 */
$system = dirname(__DIR__, 2);
$path = $system . '/modules/appointments/repositories/WaitlistRepository.php';
$src = (string) file_get_contents($path);
$ctrlPath = $system . '/modules/appointments/controllers/AppointmentController.php';
$ctrl = (string) file_get_contents($ctrlPath);
$waitlistStore = '';
$wsStart = strpos($ctrl, 'public function waitlistStore(): void');
$wsEnd = $wsStart !== false ? strpos($ctrl, 'public function waitlistUpdateStatusAction', $wsStart) : false;
if ($wsStart !== false && $wsEnd !== false && $wsEnd > $wsStart) {
    $waitlistStore = substr($ctrl, $wsStart, $wsEnd - $wsStart);
}

$checks = [
    'WaitlistRepository.php readable' => $src !== '',
    'list/count: no OR w.branch_id IS NULL leakage' => !str_contains($src, 'AND (w.branch_id = ? OR w.branch_id IS NULL)'),
    'list: strict w.branch_id = ? when filtering' => str_contains($src, 'AND w.branch_id = ?')
        && preg_match('/public function list[\s\S]*?AND w\.branch_id = \?/m', $src) === 1,
    'count: strict w.branch_id = ? when filtering' => preg_match('/public function count[\s\S]*?AND w\.branch_id = \?/m', $src) === 1,
    'countActiveByClient: no OR branch_id IS NULL when scoped' => !str_contains($src, 'AND (branch_id = ? OR branch_id IS NULL)'),
    'branch-scoped SQL uses strict branch_id = ? (countActive + auto-offer + expire)' => substr_count($src, "AND branch_id = ?") >= 4,
    'AppointmentController.php readable' => $ctrl !== '',
    'waitlistStore: validated branch via resolveAppointmentBranchForPrincipalFromOptionalRequestId' => str_contains($waitlistStore, 'resolveAppointmentBranchForPrincipalFromOptionalRequestId')
        && str_contains($waitlistStore, "'branch_id' => \$canonicalBranchId"),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
