<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);
$b = 11;

$q = static fn (string $sql, array $p = []) => (int) ($db->fetchOne($sql, $p)['c'] ?? 0);

echo "branch_id={$b}\n";
echo 'staff_active: ' . $q('SELECT COUNT(*) AS c FROM staff WHERE branch_id=? AND deleted_at IS NULL AND is_active=1', [$b]) . "\n";
echo 'cal_staff: ' . $q('SELECT COUNT(*) AS c FROM staff WHERE branch_id=? AND email LIKE ?', [$b, 'cal-seed-staff-%@example.invalid']) . "\n";
echo 'cal_clients: ' . $q('SELECT COUNT(*) AS c FROM clients WHERE branch_id=? AND email LIKE ?', [$b, 'cal-seed-client-%@example.invalid']) . "\n";
echo 'cal_services: ' . $q('SELECT COUNT(*) AS c FROM services WHERE branch_id=? AND name LIKE ? AND deleted_at IS NULL', [$b, 'CALSVC %']) . "\n";
echo 'cal_appts: ' . $q('SELECT COUNT(*) AS c FROM appointments WHERE branch_id=? AND deleted_at IS NULL AND notes LIKE ?', [$b, '[CAL_SEED_V1]%']) . "\n";
echo 'cal_blocked_may1: ' . $q(
    "SELECT COUNT(*) AS c FROM appointment_blocked_slots WHERE branch_id=? AND deleted_at IS NULL AND block_date='2026-05-01' AND title LIKE 'CAL_SEED%'",
    [$b]
) . "\n";
