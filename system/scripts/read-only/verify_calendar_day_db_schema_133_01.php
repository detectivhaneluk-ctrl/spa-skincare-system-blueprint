<?php

declare(strict_types=1);

/**
 * Read-only (DB probe): calendar day list query requires appointments.appointment_calendar_meta (migration 133).
 *
 * Usage (from repo root, with PHP + .env DB):
 *   php system/scripts/read-only/verify_calendar_day_db_schema_133_01.php
 *
 * Exit 0: column exists.
 * Exit 1: column missing or DB error — apply: cd system && php scripts/migrate.php
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
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'appointments'
           AND COLUMN_NAME = 'appointment_calendar_meta'"
    );
    $c = (int) ($row['c'] ?? 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL  Could not query information_schema: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($c < 1) {
    fwrite(STDERR, "FAIL  appointments.appointment_calendar_meta missing.\n");
    fwrite(STDERR, "      Impact: stored calendar tags (booking source, group, etc.) cannot persist; day grid uses legacy SQL fallback if app includes AvailabilityService meta fallback.\n");
    fwrite(STDERR, "      Fix: cd system && php scripts/migrate.php\n");
    fwrite(STDERR, "      Migration file: system/data/migrations/133_appointments_calendar_meta.sql\n");
    exit(1);
}

echo "OK    appointments.appointment_calendar_meta exists (migration 133 applied)\n";

try {
    $db->fetchOne('SELECT appointment_calendar_meta FROM appointments LIMIT 1');
    echo "OK    SELECT appointment_calendar_meta FROM appointments succeeds (smoke read)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL  SELECT appointment_calendar_meta: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
