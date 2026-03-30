<?php

declare(strict_types=1);

/**
 * SERVICE-VAT-RATE-DRIFT-AUDIT-01 — read-only drift report for `services.vat_rate_id`.
 *
 * Predicate (must match {@see \Modules\Sales\Repositories\VatRateRepository::isActiveIdInServiceBranchCatalog}):
 * - `vat_rate_id` NULL ⇒ allowed (no VAT assignment).
 * - `vat_rate_id` > 0 and row missing ⇒ **missing_row**
 * - Row exists and not active (`is_active != 1`) ⇒ **inactive**
 * - Row exists, active, but outside service branch catalog ⇒ **wrong_branch**
 *   - Service `branch_id` NULL ⇒ VAT row must have `branch_id` NULL (global only).
 *   - Service `branch_id` = B ⇒ VAT row must have `branch_id` NULL OR `branch_id` = B.
 * - `vat_rate_id` NOT NULL and `<= 0` ⇒ **non_positive_stored** (app treats as no VAT; storage is non-canonical).
 *
 * No INSERT/UPDATE/DELETE. Read-only SELECTs only.
 *
 * Usage (from `system/` directory):
 *   php scripts/verify_services_vat_rate_drift_readonly.php
 *   php scripts/verify_services_vat_rate_drift_readonly.php --json
 *   php scripts/verify_services_vat_rate_drift_readonly.php --fail-on-drift
 *
 * Exit codes:
 *   0 — script completed (no DB error; with --fail-on-drift, 0 only if zero problematic rows)
 *   1 — database not selected, missing table, or query failure
 *   2 — `--fail-on-drift` and one or more problematic rows exist
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';

$json = in_array('--json', $argv, true);
$failOnDrift = in_array('--fail-on-drift', $argv, true);

$pdo = app(\Core\App\Database::class)->connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "verify_services_vat_rate_drift_readonly: no database selected (check .env / config).\n");
    exit(1);
}

$check = $pdo->prepare(
    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
);
$check->execute([$dbName, 'services']);
if ($check->fetchColumn() === false) {
    fwrite(STDERR, "verify_services_vat_rate_drift_readonly: table `services` missing.\n");
    exit(1);
}
$check->execute([$dbName, 'vat_rates']);
if ($check->fetchColumn() === false) {
    fwrite(STDERR, "verify_services_vat_rate_drift_readonly: table `vat_rates` missing.\n");
    exit(1);
}

$sql = <<<'SQL'
SELECT
    s.id AS service_id,
    s.name AS service_name,
    s.branch_id AS service_branch_id,
    s.vat_rate_id AS vat_rate_id,
    vr.id AS vr_id,
    vr.branch_id AS vr_branch_id,
    vr.is_active AS vr_is_active,
    vr.name AS vr_name,
    CASE
        WHEN s.vat_rate_id IS NULL THEN 'no_vat_assignment'
        WHEN s.vat_rate_id <= 0 THEN 'non_positive_stored'
        WHEN vr.id IS NULL THEN 'missing_row'
        WHEN COALESCE(vr.is_active, 0) <> 1 THEN 'inactive'
        WHEN s.branch_id IS NULL AND vr.branch_id IS NOT NULL THEN 'wrong_branch'
        WHEN s.branch_id IS NOT NULL AND NOT (vr.branch_id IS NULL OR vr.branch_id = s.branch_id) THEN 'wrong_branch'
        ELSE 'ok'
    END AS drift_class
FROM services s
LEFT JOIN vat_rates vr ON vr.id = s.vat_rate_id
WHERE s.deleted_at IS NULL
ORDER BY drift_class, s.id
SQL;

try {
    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        throw new \RuntimeException('query failed');
    }
    /** @var list<array<string, mixed>> $rows */
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, 'verify_services_vat_rate_drift_readonly: ' . $e->getMessage() . "\n");
    exit(1);
}

$problemClasses = ['missing_row', 'inactive', 'wrong_branch', 'non_positive_stored'];
$counts = [
    'no_vat_assignment' => 0,
    'ok' => 0,
    'missing_row' => 0,
    'inactive' => 0,
    'wrong_branch' => 0,
    'non_positive_stored' => 0,
];

$problemRows = [];
foreach ($rows as $r) {
    $c = (string) ($r['drift_class'] ?? '');
    if (!isset($counts[$c])) {
        $counts[$c] = 0;
    }
    $counts[$c]++;
    if (in_array($c, $problemClasses, true)) {
        $problemRows[] = $r;
    }
}

$problemTotal = count($problemRows);

if ($json) {
    echo json_encode(
        [
            'summary' => $counts,
            'problematic_total' => $problemTotal,
            'problematic_rows' => $problemRows,
            'canonical_predicate' => 'VatRateRepository::isActiveIdInServiceBranchCatalog + ServiceService::create/update (write-time)',
        ],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
    ) . "\n";
} else {
    echo "verify_services_vat_rate_drift_readonly (SERVICE-VAT-RATE-DRIFT-AUDIT-01)\n";
    echo "Canonical predicate: VatRateRepository::isActiveIdInServiceBranchCatalog (see system/modules/sales/repositories/VatRateRepository.php)\n";
    echo "Write-time enforcement: ServiceService::create and ::update (post-merge VAT/branch) — SERVICE-VAT-RATE-UPDATE-SEAL-01; see SETTINGS-READ-SCOPE.md §4.\n\n";
    echo "Summary (non-deleted services):\n";
    foreach ($counts as $class => $n) {
        echo sprintf("  %-22s %d\n", $class, $n);
    }
    echo "\nProblematic classes: missing_row, inactive, wrong_branch, non_positive_stored\n";
    echo "Problematic row count: {$problemTotal}\n";

    if ($problemTotal > 0) {
        echo "\n--- Problematic rows (id, name, service_branch_id, vat_rate_id, class, vr_branch_id, vr_is_active) ---\n";
        foreach ($problemRows as $r) {
            echo sprintf(
                "  #%s %s | svc_branch=%s vat_id=%s | %s | vr_branch=%s active=%s\n",
                $r['service_id'],
                substr((string) $r['service_name'], 0, 60),
                $r['service_branch_id'] === null ? 'NULL' : (string) $r['service_branch_id'],
                $r['vat_rate_id'] === null ? 'NULL' : (string) $r['vat_rate_id'],
                $r['drift_class'],
                ($r['vr_id'] ?? null) === null ? '—' : ($r['vr_branch_id'] === null ? 'NULL' : (string) $r['vr_branch_id']),
                $r['vr_id'] === null ? '—' : (string) $r['vr_is_active']
            );
        }
    }

    echo "\nCleanup: optional before broader catalog work if problemTotal > 0; script does not modify data.\n";
}

$exit = 0;
if ($failOnDrift && $problemTotal > 0) {
    $exit = 2;
}

exit($exit);
