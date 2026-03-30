<?php

declare(strict_types=1);

/**
 * Dev-only: two branch-scoped services for isolation smoke. Idempotent by name.
 * Usage (from `system/`): php scripts/dev-only/seed_smoke_branch_services.php
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);

$rows = [
    [11, 'Smoke Service A Branch'],
    [12, 'Smoke Service B Branch'],
];
$ids = [];
foreach ($rows as [$bid, $name]) {
    $ex = $db->fetchOne('SELECT id FROM services WHERE name = ? AND deleted_at IS NULL', [$name]);
    if ($ex) {
        $ids[] = (int) $ex['id'];
        continue;
    }
    $db->insert('services', [
        'category_id' => null,
        'name' => $name,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'price' => 0,
        'vat_rate_id' => null,
        'is_active' => 1,
        'branch_id' => $bid,
        'created_by' => null,
        'updated_by' => null,
    ]);
    $ids[] = $db->lastInsertId();
}
echo json_encode(['service_a_branch_id' => $ids[0], 'service_b_branch_id' => $ids[1]], JSON_THROW_ON_ERROR) . PHP_EOL;
