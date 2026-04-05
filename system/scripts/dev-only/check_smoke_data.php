<?php
declare(strict_types=1);
require dirname(dirname(__DIR__)) . '/bootstrap.php';
$db = app(\Core\App\Database::class);

$admin = $db->fetchOne('SELECT id, email FROM users WHERE email = ? LIMIT 1', ['tenant-admin-a@example.test']);
echo 'admin: ' . json_encode($admin) . PHP_EOL;

$branch = $db->fetchOne('SELECT b.id, b.name, b.code, b.organization_id FROM branches b WHERE b.deleted_at IS NULL ORDER BY b.id LIMIT 1');
echo 'branch: ' . json_encode($branch) . PHP_EOL;

if ($branch) {
    $bid = (int)$branch['id'];
    $services = $db->fetchAll('SELECT id, name, duration_minutes, price FROM services WHERE branch_id = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 5', [$bid]);
    echo 'services: ' . json_encode($services) . PHP_EOL;

    $staff = $db->fetchAll('SELECT id, first_name, last_name FROM staff WHERE branch_id = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 5', [$bid]);
    echo 'staff: ' . json_encode($staff) . PHP_EOL;

    $clients = $db->fetchAll('SELECT id, first_name, last_name, email FROM clients WHERE branch_id = ? AND deleted_at IS NULL LIMIT 5', [$bid]);
    echo 'clients: ' . json_encode($clients) . PHP_EOL;
}
