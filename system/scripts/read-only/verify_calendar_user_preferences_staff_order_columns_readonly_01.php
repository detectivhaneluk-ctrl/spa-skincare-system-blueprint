<?php

declare(strict_types=1);

/**
 * Read-only (DB probe): migration 138 columns on calendar_user_preferences.
 *
 * Usage (from repo root, with PHP + .env DB):
 *   php system/scripts/read-only/verify_calendar_user_preferences_staff_order_columns_readonly_01.php
 *
 * Exit 0: columns exist.
 * Exit 1: missing columns or DB error — apply: cd system && php scripts/migrate.php
 * Exit 2: bootstrap failed (no DB / env).
 */

$systemPath = dirname(__DIR__, 2);

try {
    require $systemPath . '/bootstrap.php';
    require $systemPath . '/modules/bootstrap.php';
} catch (Throwable $e) {
    fwrite(STDERR, 'SKIP  Bootstrap failed (configure DB / env): ' . $e->getMessage() . "\n");
    exit(2);
}

/** @var \Core\App\Database $db */
$db = app(\Core\App\Database::class);

$wantCols = [
    'staff_order_scheduled_ids',
    'staff_order_freelancer_ids',
];

try {
    $rows = $db->fetchAll(
        "SELECT COLUMN_NAME AS name
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'calendar_user_preferences'"
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL  Could not query information_schema.COLUMNS: ' . $e->getMessage() . "\n");
    exit(1);
}

$have = [];
foreach ($rows as $r) {
    $n = isset($r['name']) ? (string) $r['name'] : '';
    if ($n !== '') $have[$n] = true;
}

$missing = [];
foreach ($wantCols as $c) {
    if (empty($have[$c])) $missing[] = $c;
}

if ($missing !== []) {
    fwrite(STDERR, "FAIL  calendar_user_preferences missing columns: " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "      Fix: cd system && php scripts/migrate.php\n");
    fwrite(STDERR, "      Migration file: system/data/migrations/138_calendar_user_prefs_staff_order.sql\n");
    exit(1);
}

echo "OK    calendar_user_preferences has staff order columns (migration 138)\n";
exit(0);

