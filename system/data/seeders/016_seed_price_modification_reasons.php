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
    ['code' => 'loyalty_adjustment', 'name' => 'Loyalty adjustment', 'description' => 'Manual loyalty-related reduction or correction', 'sort_order' => 10],
    ['code' => 'goodwill_discount', 'name' => 'Goodwill discount', 'description' => 'Service recovery or goodwill reduction', 'sort_order' => 20],
    ['code' => 'manager_override', 'name' => 'Manager override', 'description' => 'Manager-approved manual price override', 'sort_order' => 30],
    ['code' => 'damaged_product_adjustment', 'name' => 'Damaged product adjustment', 'description' => 'Price change due to damaged item condition', 'sort_order' => 40],
    ['code' => 'promotional_match', 'name' => 'Promotional match', 'description' => 'Manual match to an external/internal promo', 'sort_order' => 50],
    ['code' => 'manual_correction', 'name' => 'Manual correction', 'description' => 'Correction of incorrect entered price', 'sort_order' => 60],
];

foreach ($rows as $row) {
    $orgId = (int) ($row['id'] ?? 0);
    if ($orgId <= 0) {
        continue;
    }
    foreach ($defaults as $reason) {
        $db->query(
            'INSERT INTO price_modification_reasons
                (organization_id, code, name, description, sort_order, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                sort_order = VALUES(sort_order),
                is_active = 1,
                updated_at = NOW()',
            [$orgId, $reason['code'], $reason['name'], $reason['description'], $reason['sort_order']]
        );
    }
}

echo "Price modification reasons seeded.\n";

