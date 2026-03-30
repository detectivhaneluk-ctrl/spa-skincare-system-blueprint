<?php

declare(strict_types=1);

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';
$db = app(\Core\App\Database::class);
$dbname = getenv('DB_DATABASE') ?: 'spa_skincare_new';
$tables = $db->fetchAll("SHOW TABLES");
$key = 'Tables_in_' . $dbname;
$list = array_column($tables, $key);
echo "DB: " . $dbname . "\n";
echo "Tables: " . implode(', ', $list) . "\n";
$required = ['vat_rates', 'payment_methods', 'settings', 'notifications', 'register_sessions'];
foreach ($required as $t) {
    echo $t . ": " . (in_array($t, $list) ? "EXISTS" : "MISSING") . "\n";
}
