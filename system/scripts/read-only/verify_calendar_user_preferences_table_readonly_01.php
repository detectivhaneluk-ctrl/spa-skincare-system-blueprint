<?php

declare(strict_types=1);

/**
 * Read-only (DB probe): migration 134 tables calendar_user_preferences + calendar_saved_views.
 *
 * Usage (from repo root, with PHP + .env DB):
 *   php system/scripts/read-only/verify_calendar_user_preferences_table_readonly_01.php
 *
 * Exit 0: both tables exist.
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
    fwrite(STDERR, "      Impact: GET /calendar/ui-preferences degrades; POST returns 422 PERSISTENCE_UNAVAILABLE.\n");
    fwrite(STDERR, "      Fix: cd system && php scripts/migrate.php\n");
    fwrite(STDERR, "      Migration file: system/data/migrations/134_calendar_user_ui_foundation.sql\n");
    exit(1);
}

try {
    $row2 = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'calendar_saved_views'"
    );
    $c2 = (int) ($row2['c'] ?? 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL  Could not query information_schema (saved views): ' . $e->getMessage() . "\n");
    exit(1);
}

if ($c2 < 1) {
    fwrite(STDERR, "FAIL  calendar_saved_views table missing.\n");
    fwrite(STDERR, "      Impact: saved views / default view bootstrap degrade; mutating endpoints return 422 PERSISTENCE_UNAVAILABLE.\n");
    fwrite(STDERR, "      Fix: cd system && php scripts/migrate.php\n");
    fwrite(STDERR, "      Migration file: system/data/migrations/134_calendar_user_ui_foundation.sql\n");
    exit(1);
}

echo "OK    calendar_user_preferences + calendar_saved_views exist (migration 134)\n";

exit(0);
