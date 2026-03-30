<?php

declare(strict_types=1);

/**
 * H-004 read-only: branch-scoped appointment reads must not OR-in appointments.branch_id IS NULL.
 *
 * Run from project root: php system/scripts/read-only/verify_appointment_branch_null_scope_truth_h004_01.php
 *
 * Paths are under {@code system/modules/...} (this script lives in {@code system/scripts/read-only/}).
 */
$systemRoot = dirname(__DIR__, 2);

function src(string $relativeFromSystem): string
{
    global $systemRoot;

    return (string) file_get_contents($systemRoot . '/' . $relativeFromSystem);
}

$repo = src('modules/appointments/repositories/AppointmentRepository.php');
$avail = src('modules/appointments/services/AvailabilityService.php');
$dash = src('modules/dashboard/repositories/DashboardReadRepository.php');
$report = src('modules/reports/repositories/ReportRepository.php');
$mca = src('modules/marketing/repositories/MarketingContactAudienceRepository.php');
$mae = src('modules/marketing/repositories/MarketingAutomationExecutionRepository.php');

$checks = [
    'paths readable' => $repo !== '' && $avail !== '' && $dash !== '' && $report !== '' && $mca !== '' && $mae !== '',

    'AppointmentRepository: no OR branch_id IS NULL in hasRoomConflict branch path' => !str_contains(
        $repo,
        'AND (branch_id = ? OR branch_id IS NULL)'
    ),
    'AppointmentRepository: list/count still strict a.branch_id = ?' => str_contains($repo, 'AND a.branch_id = ?'),

    'AvailabilityService: no OR a.branch_id IS NULL on appointment day queries' => !str_contains(
        $avail,
        'AND (a.branch_id = ? OR a.branch_id IS NULL)'
    ),

    'DashboardReadRepository: appointments use appendExactBranch' => str_contains($dash, "appendExactBranch(\$sql, \$params, \$branchId, 'a.branch_id')")
        && !str_contains($dash, "appendBranchOrNull(\$sql, \$params, \$branchId, 'a.branch_id')"),

    'ReportRepository: appointment summaries use appendBranchFilter not OrIncludeGlobalNull' => str_contains($report, "appendBranchFilter(\$sql, \$params, \$filters, 'branch_id')")
        && str_contains($report, "appendBranchFilter(\$sql, \$params, \$filters, 'a.branch_id')")
        && !str_contains($report, "appendBranchFilterOrIncludeGlobalNull(\$sql, \$params, \$filters, 'branch_id')")
        && !str_contains($report, "appendBranchFilterOrIncludeGlobalNull(\$sql, \$params, \$filters, 'a.branch_id')"),

    'MarketingContactAudienceRepository: no appointment OR a.branch_id IS NULL pattern' => !str_contains($mca, 'a.branch_id = ? OR a.branch_id IS NULL')
        && !str_contains($mca, 'a1.branch_id = ? OR a1.branch_id IS NULL')
        && !str_contains($mca, 'a2.branch_id = ? OR a2.branch_id IS NULL')
        && !str_contains($mca, 'a3.branch_id = ? OR a3.branch_id IS NULL'),

    'MarketingAutomationExecutionRepository: appointment join strict branch' => !str_contains(
        $mae,
        'AND (a.branch_id = ? OR a.branch_id IS NULL)'
    ),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
