<?php

declare(strict_types=1);

/**
 * Seed default VAT rates (global, branch_id NULL).
 * zero=0%, standard=20%, reduced=10%. Safe documented defaults; adjust per jurisdiction.
 */

$db = \Core\App\Application::container()->get(\Core\App\Database::class);
$existing = $db->fetchOne('SELECT 1 FROM vat_rates WHERE branch_id IS NULL LIMIT 1');
if ($existing) {
    return;
}

$rates = [
    ['code' => 'zero', 'name' => 'Zero', 'rate_percent' => 0, 'sort_order' => 0],
    ['code' => 'standard', 'name' => 'Standard', 'rate_percent' => 20, 'sort_order' => 10],
    ['code' => 'reduced', 'name' => 'Reduced', 'rate_percent' => 10, 'sort_order' => 20],
];

foreach ($rates as $r) {
    $db->insert('vat_rates', [
        'branch_id' => null,
        'code' => $r['code'],
        'name' => $r['name'],
        'rate_percent' => $r['rate_percent'],
        'is_active' => 1,
        'sort_order' => $r['sort_order'],
    ]);
}
