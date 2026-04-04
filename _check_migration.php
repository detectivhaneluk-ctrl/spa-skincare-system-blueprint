<?php
define('ROOT_PATH', __DIR__ . '/system');
require_once ROOT_PATH . '/bootstrap/app.php';
$db = App\Core\Database::getInstance();
$rows = $db->query('SELECT migration, run_at FROM migrations WHERE migration LIKE "%134%" OR migration LIKE "%calendar_user%" ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
echo "MIGRATIONS:\n";
print_r($rows);
$t1 = $db->query('SHOW TABLES LIKE "calendar_user_preferences"')->fetchAll();
echo "calendar_user_preferences: " . (count($t1) > 0 ? "YES" : "NO") . "\n";
$t2 = $db->query('SHOW TABLES LIKE "calendar_saved_views"')->fetchAll();
echo "calendar_saved_views: " . (count($t2) > 0 ? "YES" : "NO") . "\n";
