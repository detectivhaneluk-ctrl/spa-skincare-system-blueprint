<?php

declare(strict_types=1);

/**
 * SERVICE-STAFF-GROUP-PIVOT-DRIFT-AUDIT-01 — read-only drift report for `service_staff_groups` vs `services.branch_id`.
 *
 * Canonical assignability (must match {@see \Modules\Staff\Repositories\StaffGroupRepository::assertIdsAssignableToService}):
 * - Each linked group must exist, `deleted_at IS NULL`, `is_active = 1`.
 * - Global service (`services.branch_id` NULL): linked group must have `staff_groups.branch_id` NULL.
 * - Branch service (`services.branch_id` = B): linked group must have `staff_groups.branch_id` NULL OR B.
 *
 * **HTTP branch move:** **SERVICE-BRANCH-MOVE-STAFF-GROUP-SEAL-01** prunes pivots when `branch_id` changes without `staff_group_ids` in payload. This script still reports legacy/SQL drift and pre-deploy rows.
 *
 * No INSERT/UPDATE/DELETE. Read-only SELECTs only.
 *
 * Usage (from `system/` directory):
 *   php scripts/verify_service_staff_group_pivot_drift_readonly.php
 *   php scripts/verify_service_staff_group_pivot_drift_readonly.php --json
 *   php scripts/verify_service_staff_group_pivot_drift_readonly.php --fail-on-drift
 *
 * Exit codes:
 *   0 — completed (with --fail-on-drift: only if zero problematic pivots)
 *   1 — database / missing table / query failure
 *   2 — --fail-on-drift and at least one problematic pivot
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';

$json = in_array('--json', $argv, true);
$failOnDrift = in_array('--fail-on-drift', $argv, true);

$pdo = app(\Core\App\Database::class)->connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "verify_service_staff_group_pivot_drift_readonly: no database selected (check .env / config).\n");
    exit(1);
}

$check = $pdo->prepare(
    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
);
foreach (['services', 'service_staff_groups', 'staff_groups'] as $tbl) {
    $check->execute([$dbName, $tbl]);
    if ($check->fetchColumn() === false) {
        fwrite(STDERR, "verify_service_staff_group_pivot_drift_readonly: required table `{$tbl}` missing.\n");
        exit(1);
    }
}

$sql = <<<'SQL'
SELECT
    ssg.id AS pivot_id,
    ssg.service_id,
    ssg.staff_group_id,
    s.branch_id AS service_branch_id,
    s.name AS service_name,
    sg.id AS sg_row_id,
    sg.name AS group_name,
    sg.branch_id AS group_branch_id,
    sg.deleted_at AS group_deleted_at,
    sg.is_active AS group_is_active,
    CASE
        WHEN sg.id IS NULL THEN 'missing_group'
        WHEN sg.deleted_at IS NOT NULL THEN 'group_soft_deleted'
        WHEN COALESCE(sg.is_active, 0) <> 1 THEN 'group_inactive'
        WHEN s.branch_id IS NULL AND sg.branch_id IS NOT NULL THEN 'wrong_branch'
        WHEN s.branch_id IS NOT NULL AND sg.branch_id IS NOT NULL AND sg.branch_id <> s.branch_id THEN 'wrong_branch'
        ELSE 'ok'
    END AS drift_class
FROM service_staff_groups ssg
INNER JOIN services s ON s.id = ssg.service_id AND s.deleted_at IS NULL
LEFT JOIN staff_groups sg ON sg.id = ssg.staff_group_id
ORDER BY drift_class, ssg.service_id, ssg.staff_group_id
SQL;

try {
    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        throw new \RuntimeException('query failed');
    }
    /** @var list<array<string, mixed>> $rows */
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, 'verify_service_staff_group_pivot_drift_readonly: ' . $e->getMessage() . "\n");
    exit(1);
}

$problemClasses = ['missing_group', 'group_soft_deleted', 'group_inactive', 'wrong_branch'];
$counts = [
    'ok' => 0,
    'missing_group' => 0,
    'group_soft_deleted' => 0,
    'group_inactive' => 0,
    'wrong_branch' => 0,
];

$problemRows = [];
$affectedServiceIds = [];
foreach ($rows as $r) {
    $c = (string) ($r['drift_class'] ?? '');
    if (!isset($counts[$c])) {
        $counts[$c] = 0;
    }
    $counts[$c]++;
    if (in_array($c, $problemClasses, true)) {
        $problemRows[] = $r;
        $affectedServiceIds[(int) $r['service_id']] = true;
    }
}

$problemPivotTotal = count($problemRows);
$affectedServiceTotal = count($affectedServiceIds);

if ($json) {
    echo json_encode(
        [
            'summary_pivot_counts' => $counts,
            'problematic_pivot_total' => $problemPivotTotal,
            'affected_service_total' => $affectedServiceTotal,
            'problematic_pivots' => $problemRows,
            'canonical_rule' => 'StaffGroupRepository::assertIdsAssignableToService',
        ],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
    ) . "\n";
} else {
    echo "verify_service_staff_group_pivot_drift_readonly (SERVICE-STAFF-GROUP-PIVOT-DRIFT-AUDIT-01)\n";
    echo "Canonical rule: StaffGroupRepository::assertIdsAssignableToService (system/modules/staff/repositories/StaffGroupRepository.php)\n";
    echo "Runtime eligibility uses active applicable links only (ServiceStaffGroupRepository + AvailabilityService); drift can still affect admin read-model and enforcement presence.\n\n";
    echo "Pivot row counts by class:\n";
    foreach ($counts as $class => $n) {
        echo sprintf("  %-22s %d\n", $class, $n);
    }
    echo "\nProblematic classes: " . implode(', ', $problemClasses) . "\n";
    echo "Problematic pivot rows: {$problemPivotTotal}\n";
    echo "Distinct services with ≥1 problematic pivot: {$affectedServiceTotal}\n";

    if ($problemPivotTotal > 0) {
        echo "\n--- Problematic pivots (pivot_id, service_id, staff_group_id, class, svc_branch, grp_branch, grp_deleted, grp_active) ---\n";
        foreach ($problemRows as $r) {
            echo sprintf(
                "  pivot=%s svc=#%s grp=#%s %s | svc_br=%s grp_br=%s del=%s act=%s %s\n",
                $r['pivot_id'],
                $r['service_id'],
                $r['staff_group_id'],
                $r['drift_class'],
                $r['service_branch_id'] === null ? 'NULL' : (string) $r['service_branch_id'],
                ($r['sg_row_id'] ?? null) === null ? '—' : ($r['group_branch_id'] === null ? 'NULL' : (string) $r['group_branch_id']),
                $r['group_deleted_at'] !== null && $r['group_deleted_at'] !== '' ? 'Y' : 'N',
                ($r['sg_row_id'] ?? null) === null ? '—' : (string) $r['group_is_active'],
                substr((string) ($r['service_name'] ?? ''), 0, 40)
            );
        }
    }

    echo "\nNo data modified. Optional cleanup before broad catalog work if problematic pivots > 0.\n";
    echo "Future optional task: seal branch-only service moves by re-validating or clearing pivots.\n";
}

$exit = 0;
if ($failOnDrift && $problemPivotTotal > 0) {
    $exit = 2;
}

exit($exit);
