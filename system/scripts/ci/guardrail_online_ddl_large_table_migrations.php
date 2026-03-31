<?php

declare(strict_types=1);

/**
 * CI guardrail — Online DDL large-table migration policy (WAVE-04).
 *
 * Checks that any migration file that:
 *  (a) touches a table classified as "large/hot" (see LARGE_TABLE_PATTERNS), and
 *  (b) uses an ALTER TABLE statement (not a CREATE INDEX … ALGORITHM=INPLACE)
 *
 * …contains the mandatory ONLINE-DDL-REQUIRED header.
 *
 * Exit code 0 = all clear.
 * Exit code 1 = one or more violations found.
 *
 * Run: php system/scripts/ci/guardrail_online_ddl_large_table_migrations.php
 */

$repoRoot = dirname(__DIR__, 3);
$migrationsDir = $repoRoot . '/system/data/migrations';

/**
 * Tables that require online DDL for any ALTER TABLE.
 * These are tables expected to have > 1M rows at 1000-salon scale.
 */
const LARGE_TABLE_PATTERNS = [
    'audit_logs',
    'appointments',
    'invoice_items',
    'invoices',
    'runtime_async_jobs',
    'public_booking_abuse_hits',
];

const ONLINE_DDL_HEADER = 'ONLINE-DDL-REQUIRED';

/**
 * Migration number from which this policy is enforced.
 * All migrations created before this policy was introduced (< 128) are grandfathered.
 */
const POLICY_APPLIES_FROM_MIGRATION = 128;

$violations = [];
$checked = 0;

$files = glob($migrationsDir . '/*.sql');
if ($files === false || $files === []) {
    echo "No migration files found in {$migrationsDir}\n";
    exit(0);
}

sort($files);

foreach ($files as $file) {
    $basename = basename($file);

    // Only enforce on migrations introduced after the policy baseline.
    $migrationNumber = (int) $basename;
    if ($migrationNumber < POLICY_APPLIES_FROM_MIGRATION) {
        $checked++;
        continue;
    }

    $content = (string) file_get_contents($file);
    $checked++;

    foreach (LARGE_TABLE_PATTERNS as $table) {
        // Look for ALTER TABLE <tablename> (with optional backtick quoting)
        $alterPattern = '/ALTER\s+TABLE\s+[`\']?' . preg_quote($table, '/') . '[`\']?\s/i';
        if (!preg_match($alterPattern, $content)) {
            continue;
        }

        // This migration alters a large/hot table. Require the ONLINE-DDL-REQUIRED header.
        if (!str_contains($content, ONLINE_DDL_HEADER)) {
            $violations[] = [
                'file' => basename($file),
                'table' => $table,
            ];
        }
    }
}

echo "Online DDL guardrail: checked {$checked} migration files\n";

if ($violations !== []) {
    echo "\nVIOLATIONS — these migrations ALTER large/hot tables without ONLINE-DDL-REQUIRED header:\n";
    foreach ($violations as $v) {
        echo "  FAIL  {$v['file']}  (table: {$v['table']})\n";
    }
    echo "\nFix: add the following comment at the top of each violating migration file:\n";
    echo "  -- ONLINE-DDL-REQUIRED: Run via pt-online-schema-change or gh-ost.\n";
    echo "  -- See ONLINE-DDL-MIGRATION-POLICY-WAVE-04.md for commands.\n";
    echo "  -- DO NOT run this file directly via system/scripts/migrate.php against a production large table.\n";
    echo "\nResult: FAIL\n";
    exit(1);
}

echo "Result: PASS — all large-table migrations carry the ONLINE-DDL-REQUIRED header.\n";
exit(0);
