<?php

declare(strict_types=1);

/**
 * CLIENT-MERGE-ASYNC-JOB-HARDENING-01 — structural proof (no DB).
 *
 * From repo root:
 *   php system/scripts/read-only/verify_client_merge_async_job_hardening_01.php
 */

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(2);
}

$system = dirname(__DIR__, 2);
$ctrl = $system . '/modules/clients/controllers/ClientController.php';
$svc = $system . '/modules/clients/services/ClientMergeJobService.php';
$repo = $system . '/modules/clients/repositories/ClientMergeJobRepository.php';
$statuses = $system . '/modules/clients/support/ClientMergeJobStatuses.php';
$mig = $system . '/data/migrations/120_client_merge_jobs.sql';
$worker = $system . '/scripts/dev-only/run_client_merge_jobs_once.php';
$boot = $system . '/modules/bootstrap/register_clients.php';

foreach ([$ctrl, $svc, $repo, $statuses, $mig, $worker, $boot] as $p) {
    if (!is_file($p)) {
        fail("missing: {$p}");
    }
}

$ctrlSrc = (string) file_get_contents($ctrl);
$mergeActionStart = strpos($ctrlSrc, 'public function mergeAction(');
if ($mergeActionStart === false) {
    fail('mergeAction not found');
}
$mergeActionEnd = strpos($ctrlSrc, 'public function customFieldsIndex(', $mergeActionStart);
$mergeActionBody = $mergeActionEnd !== false
    ? substr($ctrlSrc, $mergeActionStart, $mergeActionEnd - $mergeActionStart)
    : substr($ctrlSrc, $mergeActionStart);
if (str_contains($mergeActionBody, '->mergeClients(')) {
    fail('mergeAction must enqueue via ClientMergeJobService, not mergeClients()');
}
if (!str_contains($mergeActionBody, 'enqueueMergeJob')) {
    fail('mergeAction must call enqueueMergeJob');
}

if (!str_contains((string) file_get_contents($boot), 'ClientMergeJobService::class')) {
    fail('register_clients must wire ClientMergeJobService');
}

$clientServiceSrc = (string) file_get_contents($system . '/modules/clients/services/ClientService.php');
$svcSrc = (string) file_get_contents($svc);
if (!str_contains($clientServiceSrc, 'function mergeClientsAsActor(')) {
    fail('ClientService must expose mergeClientsAsActor for worker execution');
}
if (!str_contains($svcSrc, 'claimAndExecuteNextMergeJob')) {
    fail('ClientMergeJobService must implement claimAndExecuteNextMergeJob');
}

$st = (string) file_get_contents($statuses);
foreach (['QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED'] as $c) {
    if (!str_contains($st, "const {$c}")) {
        fail("ClientMergeJobStatuses missing {$c}");
    }
}

if (!str_contains((string) file_get_contents($mig), 'CREATE TABLE') || !str_contains((string) file_get_contents($mig), 'client_merge_jobs')) {
    fail('migration 120 must create client_merge_jobs');
}

if (!str_contains((string) file_get_contents($worker), 'claimAndExecuteNextMergeJob')) {
    fail('worker script must call claimAndExecuteNextMergeJob');
}

$repoSrc = (string) file_get_contents($repo);
if (!str_contains($repoSrc, 'findByIdForOrganization') || !str_contains($repoSrc, 'updateByIdForOrganization')) {
    fail('ClientMergeJobRepository must use organization-keyed find/update for tenant paths (PLT-TNT-01)');
}

echo "PASS: client_merge_async_job_hardening_01\n";
exit(0);
