<?php

declare(strict_types=1);

/**
 * MEMBERSHIPS-SCHEMA-TRUTH-VERIFIER-01
 *
 * Read-only check: live DB shape vs canonical expectations used by membership module code.
 * Does not alter schema or application logic.
 *
 * Usage (from `system/` directory):
 *   php scripts/verify_memberships_schema.php
 *
 * Exit codes:
 *   0 — PASS (all checks satisfied)
 *   1 — FAIL (missing table/column/critical unique index, or lifecycle status ENUM mismatch)
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "verify-memberships-schema: no database selected (check .env / config).\n");
    exit(1);
}

/** @var list<string> */
$missingTables = [];
/** @var list<string> */
$missingColumns = [];
/** @var list<string> */
$missingUniques = [];
/** @var list<string> */
$enumIssues = [];
/** @var list<string> */
$driftHints = [];

$expectations = [
    'membership_definitions' => [
        'id', 'branch_id', 'name', 'description', 'duration_days', 'price',
        'billing_enabled', 'billing_interval_unit', 'billing_interval_count',
        'renewal_price', 'renewal_invoice_due_days', 'billing_auto_renew_enabled',
        'benefits_json', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
    ],
    'client_memberships' => [
        'id', 'client_id', 'membership_definition_id', 'branch_id', 'starts_at', 'ends_at',
        'next_billing_at', 'last_billed_at', 'billing_state', 'billing_auto_renew_enabled',
        'status',
        'cancel_at_period_end', 'cancelled_at', 'paused_at', 'lifecycle_reason',
        'notes', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ],
    'membership_billing_cycles' => [
        'id', 'client_membership_id', 'billing_period_start', 'billing_period_end', 'due_at',
        'invoice_id', 'status', 'attempt_count', 'renewal_applied_at', 'created_at', 'updated_at',
    ],
    'membership_sales' => [
        'id', 'membership_definition_id', 'client_id', 'branch_id', 'invoice_id', 'client_membership_id',
        'status', 'activation_applied_at', 'starts_at', 'ends_at', 'sold_by_user_id',
        'created_at', 'updated_at',
    ],
];

/** @var array<string, list{0: string, 1: list<string>}> */
$criticalUniques = [
    'membership_billing_cycles' => ['uq_mbc_membership_period', ['client_membership_id', 'billing_period_start', 'billing_period_end']],
    'membership_sales' => ['uq_membership_sales_invoice', ['invoice_id']],
];

/**
 * @return array<string, true>
 */
function tableColumnSet(PDO $pdo, string $schema, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([$schema, $table]);
    $out = [];
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $out[(string) $row['COLUMN_NAME']] = true;
    }

    return $out;
}

function tableExists(PDO $pdo, string $schema, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = \'BASE TABLE\' LIMIT 1'
    );
    $stmt->execute([$schema, $table]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return list<string>|null null if index missing or not unique
 */
function uniqueIndexColumns(PDO $pdo, string $schema, string $table, string $indexName): ?array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0
         ORDER BY SEQ_IN_INDEX ASC'
    );
    $stmt->execute([$schema, $table, $indexName]);
    $cols = [];
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $cols[] = (string) $row['COLUMN_NAME'];
    }

    return $cols === [] ? null : $cols;
}

/** @return list<string> */
function listMigrations(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

        return is_array($rows) ? array_map('strval', $rows) : [];
    } catch (Throwable) {
        return [];
    }
}

foreach (array_keys($expectations) as $table) {
    if (!tableExists($pdo, $dbName, $table)) {
        $missingTables[] = $table;
    }
}

$columnSets = [];
foreach (array_keys($expectations) as $table) {
    if (in_array($table, $missingTables, true)) {
        continue;
    }
    $columnSets[$table] = tableColumnSet($pdo, $dbName, $table);
}

foreach ($expectations as $table => $cols) {
    if (in_array($table, $missingTables, true)) {
        continue;
    }
    $have = $columnSets[$table] ?? [];
    foreach ($cols as $col) {
        if (!isset($have[$col])) {
            $missingColumns[] = "{$table}.{$col}";
        }
    }
}

foreach ($criticalUniques as $table => [$indexName, $expectedCols]) {
    if (in_array($table, $missingTables, true)) {
        $missingUniques[] = "{$table}.{$indexName} (table missing)";
        continue;
    }
    $actual = uniqueIndexColumns($pdo, $dbName, $table, $indexName);
    if ($actual === null) {
        $missingUniques[] = "{$table}.{$indexName} (missing or not UNIQUE)";
        continue;
    }
    if ($actual !== $expectedCols) {
        $missingUniques[] = "{$table}.{$indexName} (expected columns [" . implode(', ', $expectedCols) . '], got [' . implode(', ', $actual) . '])';
    }
}

if (!in_array('client_memberships', $missingTables, true) && isset($columnSets['client_memberships']['status'])) {
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'client_memberships\' AND COLUMN_NAME = \'status\''
    );
    $stmt->execute([$dbName]);
    $type = $stmt->fetchColumn();
    if (is_string($type) && !str_contains(strtolower($type), 'paused')) {
        $enumIssues[] = 'client_memberships.status ENUM must include paused (lifecycle / access paths); got: ' . $type;
    }
}

$migrations = listMigrations($pdo);
$has067 = in_array('067_membership_subscription_billing_foundation.sql', $migrations, true);
$has068 = in_array('068_membership_sales_initial_sale.sql', $migrations, true);
$has069 = in_array('069_client_memberships_lifecycle.sql', $migrations, true);
$has070 = in_array('070_membership_definitions_billing_columns_align.sql', $migrations, true);

$billingCols = ['billing_enabled', 'billing_interval_unit', 'billing_interval_count', 'renewal_price', 'renewal_invoice_due_days', 'billing_auto_renew_enabled'];
$missingBilling = array_values(array_filter(
    $missingColumns,
    static function (string $m) use ($billingCols): bool {
        if (!str_starts_with($m, 'membership_definitions.')) {
            return false;
        }
        $c = substr($m, strlen('membership_definitions.'));

        return in_array($c, $billingCols, true);
    }
));

if ($missingBilling !== []) {
    if ($has067 && $has070) {
        $driftHints[] = 'membership_definitions billing columns are missing but migrations 067 and 070 are stamped — suspected migration-state drift or restored pre-migration table (see DB-CANONICAL-PLAN: remove erroneous migration rows or run repair SQL).';
    } elseif ($has067 && !$has070) {
        $driftHints[] = '067 is stamped but billing columns missing — run `php scripts/migrate.php` to apply 070, or repair per DB-CANONICAL-PLAN.';
    } elseif (!$has067) {
        $driftHints[] = 'Billing columns missing and 067 not stamped — run `php scripts/migrate.php` to apply pending migrations (067 defines foundation; 070 repairs definition columns only).';
    }
}

$missingLifecycleCols = array_values(array_filter(
    $missingColumns,
    static fn (string $m): bool => str_starts_with($m, 'client_memberships.')
        && in_array(substr($m, strlen('client_memberships.')), ['cancel_at_period_end', 'cancelled_at', 'paused_at', 'lifecycle_reason'], true)
));
if ($missingLifecycleCols !== [] && $has069) {
    $driftHints[] = 'client_memberships lifecycle columns missing but 069 is stamped — suspected migration-state drift (see DB-CANONICAL-PLAN).';
}

if (in_array('membership_billing_cycles', $missingTables, true) && $has067) {
    $driftHints[] = 'membership_billing_cycles table missing but 067 is stamped — suspected partial failed migration or drift; inspect DB and re-run migrate or apply 067 SQL manually after backup.';
}

if (in_array('membership_sales', $missingTables, true) && $has068) {
    $driftHints[] = 'membership_sales table missing but 068 is stamped — suspected migration-state drift.';
}

$failed = $missingTables !== [] || $missingColumns !== [] || $missingUniques !== [] || $enumIssues !== [];

fwrite(STDOUT, "MEMBERSHIPS SCHEMA VERIFICATION\n");
fwrite(STDOUT, 'DATABASE: ' . $dbName . "\n");
fwrite(STDOUT, 'RESULT: ' . ($failed ? 'FAIL' : 'PASS') . "\n\n");

if ($missingTables !== []) {
    fwrite(STDOUT, "Missing tables:\n");
    foreach ($missingTables as $t) {
        fwrite(STDOUT, "  - {$t}\n");
    }
    fwrite(STDOUT, "\n");
}

if ($missingColumns !== []) {
    fwrite(STDOUT, "Missing columns:\n");
    foreach ($missingColumns as $c) {
        fwrite(STDOUT, "  - {$c}\n");
    }
    fwrite(STDOUT, "\n");
}

if ($missingUniques !== []) {
    fwrite(STDOUT, "Missing or incorrect critical UNIQUE indexes:\n");
    foreach ($missingUniques as $u) {
        fwrite(STDOUT, "  - {$u}\n");
    }
    fwrite(STDOUT, "\n");
}

if ($enumIssues !== []) {
    fwrite(STDOUT, "ENUM / type issues:\n");
    foreach ($enumIssues as $e) {
        fwrite(STDOUT, "  - {$e}\n");
    }
    fwrite(STDOUT, "\n");
}

if ($driftHints !== []) {
    fwrite(STDOUT, "Migration / state hints:\n");
    foreach ($driftHints as $h) {
        fwrite(STDOUT, '  - ' . $h . "\n");
    }
    fwrite(STDOUT, "\n");
}

fwrite(STDOUT, "Next repair action:\n");
if ($failed) {
    fwrite(STDOUT, "  1. Back up the database.\n");
    fwrite(STDOUT, "  2. From the `system/` directory run: php scripts/migrate.php\n");
    fwrite(STDOUT, "     (default mode tolerates duplicate-column repair for 070; avoid --strict until schema matches.)\n");
    fwrite(STDOUT, "  3. If migrate reports nothing to apply but this script still fails, see `system/docs/DB-CANONICAL-PLAN.md`\n");
    fwrite(STDOUT, "     (Production: membership_definitions.billing_enabled missing) for mis-stamped `migrations` rows.\n");
    fwrite(STDOUT, "  4. Re-run: php scripts/verify_memberships_schema.php\n");
} else {
    fwrite(STDOUT, "  None required. Re-run after deployments or before release if membership migrations changed.\n");
}

fwrite(STDOUT, "\n");
exit($failed ? 1 : 0);
