<?php
declare(strict_types=1);
require dirname(dirname(__DIR__)) . '/bootstrap.php';
$db = app(\Core\App\Database::class);

$bid = 11;
$sid = 12;

// Check staff 42 schedule
$sch42 = $db->fetchAll('SELECT day_of_week, start_time, end_time FROM staff_schedules WHERE staff_id = 42');
echo 'staff 42 schedule: ' . json_encode($sch42) . PHP_EOL;

// Get appointment settings using correct key column name
$aptSets = [];
$rows = $db->fetchAll("SELECT `key`, value FROM settings WHERE branch_id = ? AND `key` LIKE 'appointment.%'", [$bid]);
echo 'branch apt settings: ' . json_encode($rows) . PHP_EOL;

$gRows = $db->fetchAll("SELECT `key`, value FROM settings WHERE branch_id IS NULL AND `key` LIKE 'appointment.%' LIMIT 10");
echo 'global apt settings: ' . json_encode($gRows) . PHP_EOL;

// Check if there's a staff 42 organization membership
$om = $db->fetchOne('SELECT * FROM organization_memberships WHERE user_id = 55 LIMIT 1');
echo 'org membership for user 55: ' . json_encode($om) . PHP_EOL;

// Check organization of branch 11
$org = $db->fetchOne('SELECT o.id, o.name FROM organizations o INNER JOIN branches b ON b.organization_id = o.id WHERE b.id = 11 LIMIT 1');
echo 'org for branch 11: ' . json_encode($org) . PHP_EOL;

// Check user 55 branch assignment
$u55 = $db->fetchOne('SELECT id, email, branch_id FROM users WHERE id = 55 LIMIT 1');
echo 'user 55: ' . json_encode($u55) . PHP_EOL;

// Check service_staff_groups for staff 42 / service 12
$ssg = $db->fetchAll('SELECT * FROM service_staff_groups WHERE service_id = 12 LIMIT 5');
echo 'service_staff_groups for svc 12: ' . json_encode($ssg) . PHP_EOL;
