<?php

declare(strict_types=1);

/**
 * Seeds default payment methods (global, branch_id NULL). Matches previous hardcoded behaviour.
 */

if (!isset($db) || !$db instanceof \Core\App\Database) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
    $db = app(\Core\App\Database::class);
}

$defaults = [
    ['cash', 'Cash', 10],
    ['card', 'Card', 20],
    ['bank_transfer', 'Bank Transfer', 30],
    ['other', 'Other', 40],
    ['gift_card', 'Gift Card', 50],
];

foreach ($defaults as [$code, $name, $sortOrder]) {
    $exists = $db->fetchOne('SELECT 1 FROM payment_methods WHERE branch_id IS NULL AND code = ? LIMIT 1', [$code]);
    if ($exists) {
        continue;
    }
    $db->query(
        'INSERT INTO payment_methods (branch_id, code, name, is_active, sort_order) VALUES (NULL, ?, ?, 1, ?)',
        [$code, $name, $sortOrder]
    );
}

echo "Payment methods seeded.\n";
