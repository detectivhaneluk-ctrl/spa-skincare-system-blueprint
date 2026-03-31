<?php

declare(strict_types=1);

/**
 * BIG-05C — Runtime migration truth audit for branch name duplicates.
 *
 * Connects to the running database and answers:
 *  1. Is migration 127 stamped as applied?
 *  2. Are there duplicate active branch names within the same organisation?
 *  3. What is the exact data shape of any duplicates?
 *
 * Usage (from project root):
 *   php system/scripts/read-only/audit_big_05c_branch_duplicate_runtime_truth.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Core\App\Database;
use Core\App\MigrationBaseline;

$db  = app(Database::class);
$pdo = $db->connection();
$basePath = dirname(__DIR__, 2);

echo "=== BIG-05C: Runtime migration + branch duplicate truth audit ===\n\n";

// ── 1. Migration baseline state ───────────────────────────────────────────────
$baseline = MigrationBaseline::collect($basePath, $pdo);
echo "--- Migration baseline ---\n";
echo "Files on disk    : " . $baseline['files_on_disk'] . "\n";
echo "Applied (stamped): " . $baseline['rows_in_migrations_table'] . "\n";
echo "Pending count    : " . $baseline['pending_count'] . "\n";
echo "Orphan stamps    : " . $baseline['orphan_stamp_count'] . "\n";
echo "Baseline aligned : " . ($baseline['baseline_aligned'] ? 'YES' : 'NO') . "\n";
echo "Latest file      : " . ($baseline['latest_file'] ?? '(none)') . "\n";
echo "Latest applied   : " . ($baseline['latest_applied'] ?? '(none)') . "\n";
if ($baseline['pending_count'] > 0) {
    echo "Pending migrations:\n";
    foreach ($baseline['pending'] as $p) {
        echo "  PENDING: {$p}\n";
    }
}
echo "\n";

// ── 2. Specific check for migration 127 ──────────────────────────────────────
$stmt127 = $pdo->prepare("SELECT migration, run_at FROM migrations WHERE migration = ?");
$stmt127->execute(['127_branches_enforce_unique_name_per_org.sql']);
$row127 = $stmt127->fetch(PDO::FETCH_ASSOC);

echo "--- Migration 127 specific state ---\n";
if ($row127 !== false) {
    echo "127 status : APPLIED\n";
    echo "127 run_at : " . ($row127['run_at'] ?? '(unknown)') . "\n";
} else {
    echo "127 status : NOT APPLIED (not in migrations table)\n";
}
echo "\n";

// ── 3. Duplicate active branch names within the same organisation ─────────────
echo "--- Duplicate active branch names per organisation ---\n";
$dupRows = $pdo->query(
    "SELECT b1.organization_id, b1.id, b1.name, b1.code, b1.deleted_at
     FROM branches b1
     INNER JOIN branches b2
         ON  b1.organization_id = b2.organization_id
         AND b1.name            = b2.name
         AND b1.id             <> b2.id
         AND b2.deleted_at IS NULL
     WHERE b1.deleted_at IS NULL
     ORDER BY b1.organization_id, b1.name, b1.id"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($dupRows)) {
    echo "RESULT: No duplicate active branch names found.\n";
} else {
    echo "RESULT: " . count($dupRows) . " duplicate row(s) found:\n\n";
    printf("  %-6s %-6s %-40s %-12s %-20s\n", 'OrgID', 'BrID', 'Name', 'Code', 'deleted_at');
    echo "  " . str_repeat('-', 90) . "\n";
    foreach ($dupRows as $r) {
        printf(
            "  %-6s %-6s %-40s %-12s %-20s\n",
            $r['organization_id'],
            $r['id'],
            substr((string) $r['name'], 0, 40),
            (string) ($r['code'] ?? 'NULL'),
            (string) ($r['deleted_at'] ?? 'NULL (active)')
        );
    }
}
echo "\n";

// ── 4. All active branches for context ───────────────────────────────────────
echo "--- All active branches (for context) ---\n";
$allBranches = $pdo->query(
    "SELECT id, organization_id, name, code FROM branches WHERE deleted_at IS NULL ORDER BY organization_id, name, id"
)->fetchAll(PDO::FETCH_ASSOC);

printf("  %-6s %-6s %-40s %-12s\n", 'BrID', 'OrgID', 'Name', 'Code');
echo "  " . str_repeat('-', 70) . "\n";
foreach ($allBranches as $r) {
    printf(
        "  %-6s %-6s %-40s %-12s\n",
        $r['id'],
        $r['organization_id'],
        substr((string) $r['name'], 0, 40),
        (string) ($r['code'] ?? 'NULL')
    );
}
echo "\n";

// ── 5. Summary verdict ────────────────────────────────────────────────────────
echo "--- Verdict ---\n";
$migration127Applied = ($row127 !== false);
$hasDuplicates = !empty($dupRows);

if (!$migration127Applied && $hasDuplicates) {
    echo "ROOT CAUSE: Migration 127 was NEVER APPLIED. Duplicate branches exist in DB.\n";
    echo "ACTION:     Run php system/scripts/migrate.php to apply pending migrations.\n";
} elseif (!$migration127Applied && !$hasDuplicates) {
    echo "PARTIAL: Migration 127 not applied but no duplicates exist currently.\n";
    echo "ACTION:  Run php system/scripts/migrate.php to stamp 127 and keep baseline aligned.\n";
} elseif ($migration127Applied && $hasDuplicates) {
    echo "ANOMALY: Migration 127 was applied but duplicates still exist.\n";
    echo "ACTION:  Manual investigation required — migration may not have matched actual data shape.\n";
} else {
    echo "CLEAN: Migration 127 applied. No duplicate active branch names.\n";
}

echo "\n=== End of audit ===\n";
