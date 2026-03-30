<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 — static proof: client merge job repository uses organization-keyed predicates for tenant HTTP paths.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_client_merge_job_repository_org_scope_plt_tnt_01.php
 */

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

$system = dirname(__DIR__, 2);
$repoPath = $system . '/modules/clients/repositories/ClientMergeJobRepository.php';
$svcPath = $system . '/modules/clients/services/ClientMergeJobService.php';

foreach ([$repoPath, $svcPath] as $p) {
    if (!is_file($p)) {
        fail("missing: {$p}");
    }
}

$repo = (string) file_get_contents($repoPath);
$svc = (string) file_get_contents($svcPath);

foreach (['findByIdForOrganization', 'findByIdForWorker', 'updateByIdForOrganization', 'updateByIdForWorker'] as $needle) {
    if (!str_contains($repo, 'function ' . $needle)) {
        fail("ClientMergeJobRepository must define {$needle}");
    }
}

if (str_contains($repo, 'public function findById(int $id)')) {
    fail('ClientMergeJobRepository must not expose unscoped public findById(int $id)');
}
if (str_contains($repo, 'public function updateById(int $id, array $patch)')) {
    fail('ClientMergeJobRepository must not expose unscoped public updateById(int $id, array $patch)');
}
if (preg_match_all('/organization_id\s*=\s*\?/', $repo) < 2) {
    fail('ClientMergeJobRepository must bind organization_id in tenant-scoped find and update SQL');
}

if (!str_contains($svc, 'findByIdForOrganization')) {
    fail('ClientMergeJobService must call findByIdForOrganization for tenant-visible job load');
}
if (str_contains($svc, '$this->jobRepo->findById(')) {
    fail('ClientMergeJobService must not call jobRepo->findById(');
}
if (str_contains($svc, '$this->jobRepo->updateById(')) {
    fail('ClientMergeJobService must not call jobRepo->updateById(');
}
if (!str_contains($svc, 'findByIdForWorker')) {
    fail('ClientMergeJobService must use findByIdForWorker after claim transaction');
}
if (!str_contains($svc, 'updateByIdForOrganization') || !str_contains($svc, 'updateByIdForWorker')) {
    fail('ClientMergeJobService must use org-scoped and worker-scoped repository updates');
}
if (!str_contains($svc, 'function markJobFailed(int $jobId, ?int $organizationId,')) {
    fail('ClientMergeJobService::markJobFailed must accept ?int organizationId for scoped failure patches');
}

echo "PASS: verify_client_merge_job_repository_org_scope_plt_tnt_01\n";
exit(0);
