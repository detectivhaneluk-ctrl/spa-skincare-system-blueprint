<?php

declare(strict_types=1);

if (!isset($db) || !$db instanceof \Core\App\Database) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
    $db = app(\Core\App\Database::class);
}

$rows = $db->fetchAll(
    'SELECT id
     FROM organizations
     WHERE deleted_at IS NULL'
);

$defaults = [
    ['code' => 'illness', 'name' => 'Illness', 'applies_to' => 'both', 'sort_order' => 10],
    ['code' => 'traffic', 'name' => 'Traffic', 'applies_to' => 'both', 'sort_order' => 20],
    ['code' => 'no_show', 'name' => 'No-show', 'applies_to' => 'no_show', 'sort_order' => 30],
    ['code' => 'personal_emergency', 'name' => 'Personal emergency', 'applies_to' => 'both', 'sort_order' => 40],
];

foreach ($rows as $row) {
    $orgId = (int) ($row['id'] ?? 0);
    if ($orgId <= 0) {
        continue;
    }
    foreach ($defaults as $reason) {
        $db->query(
            'INSERT INTO appointment_cancellation_reasons
                (organization_id, branch_id, code, name, applies_to, sort_order, is_active, created_at, updated_at)
             VALUES (?, 0, ?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                applies_to = VALUES(applies_to),
                sort_order = VALUES(sort_order),
                is_active = 1,
                updated_at = NOW()',
            [$orgId, $reason['code'], $reason['name'], $reason['applies_to'], $reason['sort_order']]
        );
    }
}

echo "Cancellation reasons seeded.\n";

