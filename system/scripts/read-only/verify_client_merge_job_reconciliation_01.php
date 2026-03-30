<?php

declare(strict_types=1);

/**
 * CLIENT-MERGE-JOB-RECONCILIATION-AND-STUCK-RUNNING-RECOVERY-01 — structural proof (no DB).
 *
 * From repo root:
 *   php system/scripts/read-only/verify_client_merge_job_reconciliation_01.php
 */

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(2);
}

$system = dirname(__DIR__, 2);
$svcPath = $system . '/modules/clients/services/ClientMergeJobService.php';
$repoPath = $system . '/modules/clients/repositories/ClientMergeJobRepository.php';
$policyPath = $system . '/modules/clients/support/ClientMergeJobStalePolicy.php';
$workerPath = $system . '/scripts/dev-only/run_client_merge_jobs_once.php';

foreach ([$svcPath, $repoPath, $policyPath, $workerPath] as $p) {
    if (!is_file($p)) {
        fail("missing: {$p}");
    }
}

$svc = (string) file_get_contents($svcPath);
$repo = (string) file_get_contents($repoPath);
$policy = (string) file_get_contents($policyPath);

if (!str_contains($policy, 'RUNNING_STALE_MINUTES')) {
    fail('ClientMergeJobStalePolicy must define RUNNING_STALE_MINUTES');
}

if (!str_contains($repo, 'findOldestStaleRunningForUpdate')) {
    fail('ClientMergeJobRepository must expose findOldestStaleRunningForUpdate');
}
if (!str_contains($repo, 'findByIdForOrganization')) {
    fail('ClientMergeJobRepository must expose findByIdForOrganization (org-keyed tenant read)');
}
if (!str_contains($repo, 'updateByIdForOrganization')) {
    fail('ClientMergeJobRepository must expose updateByIdForOrganization (org-keyed tenant write)');
}
if (!str_contains($repo, 'DATE_SUB(NOW()') || !str_contains($repo, 'FOR UPDATE')) {
    fail('stale running query must use age predicate and FOR UPDATE');
}

if (!str_contains($svc, 'function reconcileStaleRunningJobs(')) {
    fail('ClientMergeJobService must expose reconcileStaleRunningJobs');
}
if (!str_contains($svc, 'reconciled_completed_merge')) {
    fail('reconciliation must set current_step reconciled_completed_merge on success path');
}
if (!str_contains($svc, 'secondary_merged_into_primary')) {
    fail('reconciliation must record detection secondary_merged_into_primary in result_json');
}
if (!str_contains($svc, 'reconcileStaleRunningJobs')) {
    fail('service must invoke reconcile path from worker entry');
}
if (!str_contains($svc, 'claimAndExecuteNextMergeJob')) {
    fail('claimAndExecuteNextMergeJob must remain');
}
if (!preg_match('/claimAndExecuteNextMergeJob[\s\S]{0,800}reconcileStaleRunningJobs/s', $svc)) {
    fail('claimAndExecuteNextMergeJob must call reconcileStaleRunningJobs before claim');
}

foreach (['QUEUED', 'SUCCEEDED', 'FAILED'] as $st) {
    if (!str_contains($svc, $st)) {
        fail("reconciliation transitions should reference status constant context: {$st}");
    }
}

echo "PASS: client_merge_job_reconciliation_01\n";
exit(0);
