<?php

declare(strict_types=1);

/**
 * H-004: branch-filtered appointment list/count must not OR-in NULL branch_id rows.
 */
$system = dirname(__DIR__, 2);
$path = $system . '/modules/appointments/repositories/AppointmentRepository.php';
$src = (string) file_get_contents($path);

$checks = [
    'AppointmentRepository.php readable' => $src !== '',
    'list/count: no OR a.branch_id IS NULL leakage' => !str_contains($src, 'AND (a.branch_id = ? OR a.branch_id IS NULL)'),
    'list: strict a.branch_id = ? when filtering' => str_contains($src, 'AND a.branch_id = ?')
        && preg_match('/public function list[\s\S]*?AND a\.branch_id = \?/m', $src) === 1,
    'count: strict a.branch_id = ? when filtering' => preg_match('/public function count[\s\S]*?AND a\.branch_id = \?/m', $src) === 1,
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
