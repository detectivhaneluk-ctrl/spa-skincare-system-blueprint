<?php

declare(strict_types=1);

/**
 * Read-only (DB probe): calendar toolbar prefs POST requires calendar_user_preferences (migration 134).
 *
 * Usage (from repo root, with PHP + .env DB):
 *   php system/scripts/read-only/verify_calendar_user_preferences_table_readonly_01.php
 *
 * Exit 0: table exists.
 * Exit 1: table missing or DB error — apply: cd system && php scripts/migrate.php
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

try {
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'calendar_user_preferences'"
    );
    $c = (int) ($row['c'] ?? 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL  Could not query information_schema: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($c < 1) {
    fwrite(STDERR, "FAIL  calendar_user_preferences table missing.\n");
    fwrite(STDERR, "      Impact: POST /calendar/ui-preferences returns 500; Zoom/Staff prefs cannot persist.\n");
    fwrite(STDERR, "      Fix: cd system && php scripts/migrate.php\n");
    fwrite(STDERR, "      Migration file: system/data/migrations/134_calendar_user_ui_foundation.sql\n");
    exit(1);
}

echo "OK    calendar_user_preferences exists (migration 134 applied)\n";

exit(0);
