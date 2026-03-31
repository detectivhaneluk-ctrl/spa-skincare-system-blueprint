<?php

declare(strict_types=1);

/**
 * BIG-07 Verification Script — Client-Owned Resources Domain Phase-4 Kernel Migration
 *
 * Covers:
 *   1.  ClientService    — BranchContext/TenantOwnedDataScopeGuard removed, RequestContextHolder injected
 *   2.  ClientIssueFlagService — BranchContext/TenantOwnedDataScopeGuard removed, RequestContextHolder injected
 *   3.  ClientRegistrationService — BranchContext/TenantOwnedDataScopeGuard removed, RequestContextHolder injected
 *   4.  ClientMergeJobService — direct DB claim calls moved to repo, RequestContextHolder injected
 *   5.  ClientRepository — canonical TenantContext-first methods added
 *   6.  ClientIssueFlagRepository — canonical TenantContext-first methods added
 *   7.  ClientMergeJobRepository — claimNextQueuedJob/claimSpecificQueuedJob + findOwnedJobById added
 *   8.  ClientRegistrationRequestRepository — canonical TenantContext-first methods added
 *   9.  ClientFieldDefinitionRepository — canonical TenantContext-first methods added
 *  10.  Bootstrap DI — migrated services use RequestContextHolder (not BranchContext)
 *  11.  Service layer DB ban guardrail — covers CLIENT_P4 services, passes
 *  12.  Id-only repo freeze guardrail  — covers client repos, passes
 *  13.  Core client behavior contracts preserved (methods still exist post-migration)
 *  14.  No regression to prior migrated slices (appointments, media pilot, sales)
 *
 * Exception notes (preserved, not violations):
 *   - ClientService retains db->fetchOne for MySQL advisory locking (GET_LOCK/RELEASE_LOCK)
 *     in mergeClientsAsActor() — infrastructure, same rationale as WaitlistService BIG-04.
 *   - ClientMergeJobService retains BranchContext + OrganizationContext for background worker
 *     context establishment (not DB data access) — async job execution pattern.
 *
 * Run from repo root: php system/scripts/read-only/verify_big_07_client_owned_resources_migration_01.php
 */

$repoRoot = dirname(__DIR__, 3);

$passed = 0;
$failed = 0;
$errors = [];

function assertThat(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        ++$passed;
    } else {
        ++$failed;
        $errors[] = 'FAIL: ' . $label . ($detail !== '' ? "\n       {$detail}" : '');
    }
}

function fileContent(string $repoRoot, string $rel): string
{
    $path = $repoRoot . '/' . $rel;
    if (!is_file($path)) {
        return '';
    }
    return (string) file_get_contents($path);
}

echo "BIG-07 verification — Client-Owned Resources Domain Phase-4 Kernel Migration\n";
echo str_repeat('=', 72) . "\n\n";

// ==========================================================================
// SECTION 1: ClientService — BranchContext removed, RequestContextHolder injected
// ==========================================================================
echo "Section 1: ClientService — BranchContext removed, RequestContextHolder injected\n";

$clientSvc = fileContent($repoRoot, 'system/modules/clients/services/ClientService.php');
assertThat('ClientService.php exists', $clientSvc !== '');
assertThat('ClientService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $clientSvc));
assertThat('ClientService: no TenantOwnedDataScopeGuard use statement', !preg_match('/^use Core\\\\Tenant\\\\TenantOwnedDataScopeGuard;/m', $clientSvc));
assertThat('ClientService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $clientSvc) === 1);
assertThat('ClientService: has AccessDeniedException use statement', preg_match('/^use Core\\\\Errors\\\\AccessDeniedException;/m', $clientSvc) === 1);
assertThat('ClientService: injects RequestContextHolder', str_contains($clientSvc, 'RequestContextHolder $contextHolder'));
assertThat('ClientService: no BranchContext constructor param', !str_contains($clientSvc, 'BranchContext $branchContext'));
assertThat('ClientService: no TenantOwnedDataScopeGuard constructor param', !str_contains($clientSvc, 'TenantOwnedDataScopeGuard $tenantScopeGuard'));
assertThat('ClientService: calls requireContext()', str_contains($clientSvc, '->requireContext()'));
assertThat('ClientService: calls requireResolvedTenant()', str_contains($clientSvc, '->requireResolvedTenant()'));
assertThat('ClientService: no ->assertBranchMatchOrGlobalEntity', !str_contains($clientSvc, '->assertBranchMatchOrGlobalEntity'));
assertThat('ClientService: no ->enforceBranchOnCreate', !str_contains($clientSvc, '->enforceBranchOnCreate'));
assertThat('ClientService: no ->requireResolvedTenantScope', !str_contains($clientSvc, '->requireResolvedTenantScope'));
assertThat('ClientService: no direct ->fetchAll()', !preg_match('/->fetchAll\s*\(/', $clientSvc));
assertThat('ClientService: no direct ->query() (non-GET_LOCK)', !preg_match('/\$this->db->query\s*\(/', $clientSvc));
assertThat('ClientService: no direct ->insert()', !preg_match('/\$this->db->insert\s*\(/', $clientSvc));
assertThat('ClientService: no direct ->lastInsertId()', !preg_match('/\$this->db->lastInsertId\s*\(/', $clientSvc));
assertThat('ClientService: advisory lock exception preserved (GET_LOCK)', str_contains($clientSvc, 'GET_LOCK'));
assertThat('ClientService: advisory lock exception preserved (RELEASE_LOCK)', str_contains($clientSvc, 'RELEASE_LOCK'));

// ==========================================================================
// SECTION 2: ClientIssueFlagService — BranchContext removed, RequestContextHolder injected
// ==========================================================================
echo "\nSection 2: ClientIssueFlagService — BranchContext removed, RequestContextHolder injected\n";

$flagSvc = fileContent($repoRoot, 'system/modules/clients/services/ClientIssueFlagService.php');
assertThat('ClientIssueFlagService.php exists', $flagSvc !== '');
assertThat('ClientIssueFlagService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $flagSvc));
assertThat('ClientIssueFlagService: no TenantOwnedDataScopeGuard use statement', !preg_match('/^use Core\\\\Tenant\\\\TenantOwnedDataScopeGuard;/m', $flagSvc));
assertThat('ClientIssueFlagService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $flagSvc) === 1);
assertThat('ClientIssueFlagService: injects RequestContextHolder', str_contains($flagSvc, 'RequestContextHolder $contextHolder'));
assertThat('ClientIssueFlagService: no BranchContext constructor param', !str_contains($flagSvc, 'BranchContext $branchContext'));
assertThat('ClientIssueFlagService: calls requireContext()', str_contains($flagSvc, '->requireContext()'));
assertThat('ClientIssueFlagService: calls requireResolvedTenant()', str_contains($flagSvc, '->requireResolvedTenant()'));
assertThat('ClientIssueFlagService: no ->assertBranchMatchOrGlobalEntity', !str_contains($flagSvc, '->assertBranchMatchOrGlobalEntity'));
assertThat('ClientIssueFlagService: no ->enforceBranchOnCreate', !str_contains($flagSvc, '->enforceBranchOnCreate'));
assertThat('ClientIssueFlagService: no direct ->fetchOne()', !preg_match('/->fetchOne\s*\(/', $flagSvc));
assertThat('ClientIssueFlagService: no direct ->fetchAll()', !preg_match('/->fetchAll\s*\(/', $flagSvc));
assertThat('ClientIssueFlagService: no direct ->query()', !preg_match('/\$this->db->query\s*\(/', $flagSvc));

// ==========================================================================
// SECTION 3: ClientRegistrationService — BranchContext removed, RequestContextHolder injected
// ==========================================================================
echo "\nSection 3: ClientRegistrationService — BranchContext removed, RequestContextHolder injected\n";

$regSvc = fileContent($repoRoot, 'system/modules/clients/services/ClientRegistrationService.php');
assertThat('ClientRegistrationService.php exists', $regSvc !== '');
assertThat('ClientRegistrationService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $regSvc));
assertThat('ClientRegistrationService: no TenantOwnedDataScopeGuard use statement', !preg_match('/^use Core\\\\Tenant\\\\TenantOwnedDataScopeGuard;/m', $regSvc));
assertThat('ClientRegistrationService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $regSvc) === 1);
assertThat('ClientRegistrationService: injects RequestContextHolder', str_contains($regSvc, 'RequestContextHolder $contextHolder'));
assertThat('ClientRegistrationService: no BranchContext constructor param', !str_contains($regSvc, 'BranchContext $branchContext'));
assertThat('ClientRegistrationService: calls requireContext()', str_contains($regSvc, '->requireContext()'));
assertThat('ClientRegistrationService: calls requireResolvedTenant()', str_contains($regSvc, '->requireResolvedTenant()'));
assertThat('ClientRegistrationService: no ->assertBranchMatchOrGlobalEntity', !str_contains($regSvc, '->assertBranchMatchOrGlobalEntity'));
assertThat('ClientRegistrationService: no ->enforceBranchOnCreate', !str_contains($regSvc, '->enforceBranchOnCreate'));
assertThat('ClientRegistrationService: no direct ->fetchOne()', !preg_match('/->fetchOne\s*\(/', $regSvc));
assertThat('ClientRegistrationService: no direct ->fetchAll()', !preg_match('/->fetchAll\s*\(/', $regSvc));
assertThat('ClientRegistrationService: no direct ->query()', !preg_match('/\$this->db->query\s*\(/', $regSvc));

// ==========================================================================
// SECTION 4: ClientMergeJobService — direct DB calls moved to repo, RequestContextHolder injected
// ==========================================================================
echo "\nSection 4: ClientMergeJobService — direct DB claim calls moved to repo, RequestContextHolder injected\n";

$mergeSvc = fileContent($repoRoot, 'system/modules/clients/services/ClientMergeJobService.php');
assertThat('ClientMergeJobService.php exists', $mergeSvc !== '');
assertThat('ClientMergeJobService: no TenantOwnedDataScopeGuard use statement', !preg_match('/^use Core\\\\Tenant\\\\TenantOwnedDataScopeGuard;/m', $mergeSvc));
assertThat('ClientMergeJobService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $mergeSvc) === 1);
assertThat('ClientMergeJobService: injects RequestContextHolder', str_contains($mergeSvc, 'RequestContextHolder $contextHolder'));
assertThat('ClientMergeJobService: calls requireContext()', str_contains($mergeSvc, '->requireContext()'));
assertThat('ClientMergeJobService: calls requireResolvedTenant()', str_contains($mergeSvc, '->requireResolvedTenant()'));
assertThat('ClientMergeJobService: no direct ->fetchOne() (moved to repo)', !preg_match('/\$this->db->fetchOne\s*\(/', $mergeSvc));
assertThat('ClientMergeJobService: no direct ->fetchAll()', !preg_match('/\$this->db->fetchAll\s*\(/', $mergeSvc));
assertThat('ClientMergeJobService: no direct ->query() (moved to repo)', !preg_match('/\$this->db->query\s*\(/', $mergeSvc));
assertThat('ClientMergeJobService: no direct ->insert()', !preg_match('/\$this->db->insert\s*\(/', $mergeSvc));
assertThat('ClientMergeJobService: delegates claim to jobRepo->claimNextQueuedJob', str_contains($mergeSvc, '->claimNextQueuedJob()'));
assertThat('ClientMergeJobService: delegates claim to jobRepo->claimSpecificQueuedJob', str_contains($mergeSvc, '->claimSpecificQueuedJob('));
assertThat('ClientMergeJobService: uses jobRepo->createJob (not ->insert)', str_contains($mergeSvc, '->createJob('));
assertThat('ClientMergeJobService: enqueue path uses RequestContextHolder', str_contains($mergeSvc, '$this->contextHolder->requireContext()'));
assertThat('ClientMergeJobService: no ->requireResolvedTenantScope', !str_contains($mergeSvc, '->requireResolvedTenantScope'));

// ==========================================================================
// SECTION 5: ClientRepository — canonical TenantContext-first methods exist
// ==========================================================================
echo "\nSection 5: ClientRepository — canonical TenantContext-first methods exist\n";

$clientRepo = fileContent($repoRoot, 'system/modules/clients/repositories/ClientRepository.php');
assertThat('ClientRepository.php exists', $clientRepo !== '');
assertThat('ClientRepository: imports TenantContext', str_contains($clientRepo, 'use Core\Kernel\TenantContext'));
assertThat('ClientRepository: has findOwnedClientById(TenantContext', str_contains($clientRepo, 'public function findOwnedClientById(TenantContext'));
assertThat('ClientRepository: has loadOwnedClientForUpdate(TenantContext', str_contains($clientRepo, 'public function loadOwnedClientForUpdate(TenantContext'));
assertThat('ClientRepository: has loadOwnedLiveReadableForProfile(TenantContext', str_contains($clientRepo, 'public function loadOwnedLiveReadableForProfile(TenantContext'));
assertThat('ClientRepository: has listOwnedClientsForBranch(TenantContext', str_contains($clientRepo, 'public function listOwnedClientsForBranch(TenantContext'));
assertThat('ClientRepository: has countOwnedClientsForBranch(TenantContext', str_contains($clientRepo, 'public function countOwnedClientsForBranch(TenantContext'));
assertThat('ClientRepository: canonical methods call requireResolvedTenant()', substr_count($clientRepo, '->requireResolvedTenant()') >= 4);

// ==========================================================================
// SECTION 6: ClientIssueFlagRepository — canonical TenantContext-first methods exist
// ==========================================================================
echo "\nSection 6: ClientIssueFlagRepository — canonical TenantContext-first methods exist\n";

$flagRepo = fileContent($repoRoot, 'system/modules/clients/repositories/ClientIssueFlagRepository.php');
assertThat('ClientIssueFlagRepository.php exists', $flagRepo !== '');
assertThat('ClientIssueFlagRepository: imports TenantContext', str_contains($flagRepo, 'use Core\Kernel\TenantContext'));
assertThat('ClientIssueFlagRepository: has findOwnedFlagById(TenantContext', str_contains($flagRepo, 'public function findOwnedFlagById(TenantContext'));
assertThat('ClientIssueFlagRepository: has listOwnedFlagsForClient(TenantContext', str_contains($flagRepo, 'public function listOwnedFlagsForClient(TenantContext'));
assertThat('ClientIssueFlagRepository: has mutateCreateOwnedFlag(TenantContext', str_contains($flagRepo, 'public function mutateCreateOwnedFlag(TenantContext'));
assertThat('ClientIssueFlagRepository: has mutateUpdateOwnedFlag(TenantContext', str_contains($flagRepo, 'public function mutateUpdateOwnedFlag(TenantContext'));
assertThat('ClientIssueFlagRepository: canonical methods call requireResolvedTenant()', substr_count($flagRepo, '->requireResolvedTenant()') >= 4);

// ==========================================================================
// SECTION 7: ClientMergeJobRepository — canonical TenantContext methods + claim helpers exist
// ==========================================================================
echo "\nSection 7: ClientMergeJobRepository — canonical TenantContext methods + claim helpers exist\n";

$mergeRepo = fileContent($repoRoot, 'system/modules/clients/repositories/ClientMergeJobRepository.php');
assertThat('ClientMergeJobRepository.php exists', $mergeRepo !== '');
assertThat('ClientMergeJobRepository: imports TenantContext', str_contains($mergeRepo, 'use Core\Kernel\TenantContext'));
assertThat('ClientMergeJobRepository: has createJob() (not insert)', str_contains($mergeRepo, 'public function createJob(array $row)'));
assertThat('ClientMergeJobRepository: has findOwnedJobById(TenantContext', str_contains($mergeRepo, 'public function findOwnedJobById(TenantContext'));
assertThat('ClientMergeJobRepository: has claimNextQueuedJob()', str_contains($mergeRepo, 'public function claimNextQueuedJob():'));
assertThat('ClientMergeJobRepository: has claimSpecificQueuedJob(int $jobId)', str_contains($mergeRepo, 'public function claimSpecificQueuedJob(int $jobId)'));
assertThat('ClientMergeJobRepository: claimNextQueuedJob handles own transaction', str_contains($mergeRepo, '$pdo->beginTransaction()'));
assertThat('ClientMergeJobRepository: findOwnedJobById calls requireResolvedTenant()', str_contains($mergeRepo, '$ctx->requireResolvedTenant()'));
assertThat('ClientMergeJobRepository: no legacy insert() public method', !preg_match('/^\s*public function insert\s*\(/m', $mergeRepo));

// ==========================================================================
// SECTION 8: ClientRegistrationRequestRepository — canonical TenantContext-first methods exist
// ==========================================================================
echo "\nSection 8: ClientRegistrationRequestRepository — canonical TenantContext-first methods exist\n";

$regRepo = fileContent($repoRoot, 'system/modules/clients/repositories/ClientRegistrationRequestRepository.php');
assertThat('ClientRegistrationRequestRepository.php exists', $regRepo !== '');
assertThat('ClientRegistrationRequestRepository: imports TenantContext', str_contains($regRepo, 'use Core\Kernel\TenantContext'));
assertThat('ClientRegistrationRequestRepository: has findOwnedRegistration(TenantContext', str_contains($regRepo, 'public function findOwnedRegistration(TenantContext'));
assertThat('ClientRegistrationRequestRepository: has listOwnedRegistrations(TenantContext', str_contains($regRepo, 'public function listOwnedRegistrations(TenantContext'));
assertThat('ClientRegistrationRequestRepository: has countOwnedRegistrations(TenantContext', str_contains($regRepo, 'public function countOwnedRegistrations(TenantContext'));
assertThat('ClientRegistrationRequestRepository: has mutateCreateOwnedRegistration(TenantContext', str_contains($regRepo, 'public function mutateCreateOwnedRegistration(TenantContext'));
assertThat('ClientRegistrationRequestRepository: has mutateUpdateOwnedRegistration(TenantContext', str_contains($regRepo, 'public function mutateUpdateOwnedRegistration(TenantContext'));
assertThat('ClientRegistrationRequestRepository: canonical methods call requireResolvedTenant()', substr_count($regRepo, '->requireResolvedTenant()') >= 5);

// ==========================================================================
// SECTION 9: ClientFieldDefinitionRepository — canonical TenantContext-first methods exist
// ==========================================================================
echo "\nSection 9: ClientFieldDefinitionRepository — canonical TenantContext-first methods exist\n";

$fieldDefRepo = fileContent($repoRoot, 'system/modules/clients/repositories/ClientFieldDefinitionRepository.php');
assertThat('ClientFieldDefinitionRepository.php exists', $fieldDefRepo !== '');
assertThat('ClientFieldDefinitionRepository: imports TenantContext', str_contains($fieldDefRepo, 'use Core\Kernel\TenantContext'));
assertThat('ClientFieldDefinitionRepository: has listOwnedDefinitionsForBranch(TenantContext', str_contains($fieldDefRepo, 'public function listOwnedDefinitionsForBranch(TenantContext'));
assertThat('ClientFieldDefinitionRepository: has findOwnedDefinition(TenantContext', str_contains($fieldDefRepo, 'public function findOwnedDefinition(TenantContext'));
assertThat('ClientFieldDefinitionRepository: has mutateCreateOwnedDefinition(TenantContext', str_contains($fieldDefRepo, 'public function mutateCreateOwnedDefinition(TenantContext'));
assertThat('ClientFieldDefinitionRepository: has mutateUpdateOwnedDefinition(TenantContext', str_contains($fieldDefRepo, 'public function mutateUpdateOwnedDefinition(TenantContext'));
assertThat('ClientFieldDefinitionRepository: has mutateSoftDeleteOwnedDefinition(TenantContext', str_contains($fieldDefRepo, 'public function mutateSoftDeleteOwnedDefinition(TenantContext'));
assertThat('ClientFieldDefinitionRepository: canonical methods call requireResolvedTenant()', substr_count($fieldDefRepo, '->requireResolvedTenant()') >= 5);

// ==========================================================================
// SECTION 10: Bootstrap DI — migrated services use RequestContextHolder
// ==========================================================================
echo "\nSection 10: Bootstrap DI — migrated services inject RequestContextHolder\n";

$bootstrap = fileContent($repoRoot, 'system/modules/bootstrap/register_clients.php');
assertThat('register_clients.php exists', $bootstrap !== '');
assertThat('Bootstrap: ClientService gets RequestContextHolder', str_contains($bootstrap, 'ClientService::class') && str_contains($bootstrap, 'RequestContextHolder::class'));
assertThat('Bootstrap: ClientMergeJobService gets RequestContextHolder', str_contains($bootstrap, 'ClientMergeJobService::class') && str_contains($bootstrap, 'RequestContextHolder::class'));
assertThat('Bootstrap: ClientRegistrationService gets RequestContextHolder', str_contains($bootstrap, 'ClientRegistrationService::class') && str_contains($bootstrap, 'RequestContextHolder::class'));
assertThat('Bootstrap: ClientIssueFlagService gets RequestContextHolder', str_contains($bootstrap, 'ClientIssueFlagService::class') && str_contains($bootstrap, 'RequestContextHolder::class'));
assertThat('Bootstrap: ClientService no longer injects BranchContext (raw pattern)', !preg_match('/singleton\s*\(\s*\\\\Modules\\\\Clients\\\\Services\\\\ClientService::class[^)]*BranchContext/s', $bootstrap));

// ==========================================================================
// SECTION 11: Service layer DB ban guardrail — covers CLIENT_P4 services
// ==========================================================================
echo "\nSection 11: Service layer DB ban guardrail — covers CLIENT_P4 services\n";

$guardrail1 = fileContent($repoRoot, 'system/scripts/ci/guardrail_service_layer_db_ban.php');
assertThat('guardrail_service_layer_db_ban.php exists', $guardrail1 !== '');
assertThat('Guardrail 1: covers CLIENT_P4 phase comment', str_contains($guardrail1, 'CLIENT_P4 phase'));
assertThat('Guardrail 1: includes ClientMergeJobService', str_contains($guardrail1, 'clients/services/ClientMergeJobService.php'));
assertThat('Guardrail 1: includes ClientRegistrationService', str_contains($guardrail1, 'clients/services/ClientRegistrationService.php'));
assertThat('Guardrail 1: includes ClientIssueFlagService', str_contains($guardrail1, 'clients/services/ClientIssueFlagService.php'));
assertThat('Guardrail 1: ClientService advisory lock exception documented', str_contains($guardrail1, 'ClientService') && str_contains($guardrail1, 'advisory'));

$phpBin = PHP_BINARY;
$guardrailScript = $repoRoot . '/system/scripts/ci/guardrail_service_layer_db_ban.php';
$g1Output = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($guardrailScript) . ' 2>&1');
$g1Pass = str_contains((string) $g1Output, 'PASS');
assertThat('Guardrail 1 passes live (service layer DB ban)', $g1Pass, $g1Pass ? '' : 'Output: ' . trim((string) $g1Output));

// ==========================================================================
// SECTION 12: Id-only repo freeze guardrail — covers client repos
// ==========================================================================
echo "\nSection 12: Id-only repo freeze guardrail — covers client repos\n";

$guardrail2 = fileContent($repoRoot, 'system/scripts/ci/guardrail_id_only_repo_api_freeze.php');
assertThat('guardrail_id_only_repo_api_freeze.php exists', $guardrail2 !== '');
assertThat('Guardrail 2: covers CLIENT_P4 phase comment', str_contains($guardrail2, 'CLIENT_P4 phase'));
assertThat('Guardrail 2: includes ClientRepository', str_contains($guardrail2, 'clients/repositories/ClientRepository.php'));
assertThat('Guardrail 2: includes ClientIssueFlagRepository', str_contains($guardrail2, 'clients/repositories/ClientIssueFlagRepository.php'));
assertThat('Guardrail 2: includes ClientMergeJobRepository', str_contains($guardrail2, 'clients/repositories/ClientMergeJobRepository.php'));
assertThat('Guardrail 2: includes ClientRegistrationRequestRepository', str_contains($guardrail2, 'clients/repositories/ClientRegistrationRequestRepository.php'));
assertThat('Guardrail 2: includes ClientFieldDefinitionRepository', str_contains($guardrail2, 'clients/repositories/ClientFieldDefinitionRepository.php'));
assertThat('Guardrail 2: ClientRepository lock methods frozen in allowlist', str_contains($guardrail2, 'lockActiveByEmailBranch'));
assertThat('Guardrail 2: ClientFieldDefinitionRepository list() frozen in allowlist', str_contains($guardrail2, "'list'") || str_contains($guardrail2, '"list"'));

$guardrailScript2 = $repoRoot . '/system/scripts/ci/guardrail_id_only_repo_api_freeze.php';
$g2Output = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($guardrailScript2) . ' 2>&1');
$g2Pass = str_contains((string) $g2Output, 'PASS');
assertThat('Guardrail 2 passes live (id-only repo API freeze)', $g2Pass, $g2Pass ? '' : 'Output: ' . trim((string) $g2Output));

// ==========================================================================
// SECTION 13: Core client behavior contracts preserved
// ==========================================================================
echo "\nSection 13: Core client behavior contracts preserved post-migration\n";

assertThat('ClientService: create() method still exists', str_contains($clientSvc, 'public function create(array $data)'));
assertThat('ClientService: update() method still exists', str_contains($clientSvc, 'public function update(int $id, array $data)'));
assertThat('ClientService: delete() method still exists', str_contains($clientSvc, 'public function delete(int $id)'));
assertThat('ClientService: addClientNote() still exists', str_contains($clientSvc, 'public function addClientNote(int $clientId'));
assertThat('ClientService: deleteClientNote() still exists', str_contains($clientSvc, 'public function deleteClientNote(int $clientId'));
assertThat('ClientService: mergeClients() still exists', str_contains($clientSvc, 'public function mergeClients(int $primaryId'));
assertThat('ClientService: mergeClientsAsActor() still exists', str_contains($clientSvc, 'public function mergeClientsAsActor(int $primaryId'));
assertThat('ClientService: getMergePreview() still exists', str_contains($clientSvc, 'public function getMergePreview(int $primaryId'));
assertThat('ClientService: createCustomFieldDefinition() still exists', str_contains($clientSvc, 'public function createCustomFieldDefinition('));
assertThat('ClientService: updateCustomFieldDefinition() still exists', str_contains($clientSvc, 'public function updateCustomFieldDefinition('));
assertThat('ClientService: deleteCustomFieldDefinition() still exists', str_contains($clientSvc, 'public function deleteCustomFieldDefinition('));
assertThat('ClientService: updateProfileNotes() still exists', str_contains($clientSvc, 'public function updateProfileNotes('));
assertThat('ClientIssueFlagService: create() still exists', str_contains($flagSvc, 'public function create(array $data)'));
assertThat('ClientIssueFlagService: resolve() still exists', str_contains($flagSvc, 'public function resolve(int $id'));
assertThat('ClientRegistrationService: create() still exists', str_contains($regSvc, 'public function create(array $data)'));
assertThat('ClientRegistrationService: updateStatus() still exists', str_contains($regSvc, 'public function updateStatus(int $id'));
assertThat('ClientRegistrationService: convert() still exists', str_contains($regSvc, 'public function convert(int $id'));
assertThat('ClientMergeJobService: enqueueMergeJob() still exists', str_contains($mergeSvc, 'public function enqueueMergeJob('));
assertThat('ClientMergeJobService: getJobForCurrentTenant() still exists', str_contains($mergeSvc, 'public function getJobForCurrentTenant('));
assertThat('ClientMergeJobService: claimAndExecuteNextMergeJob() still exists', str_contains($mergeSvc, 'public function claimAndExecuteNextMergeJob()'));
assertThat('ClientMergeJobService: reconcileStaleRunningJobs() still exists', str_contains($mergeSvc, 'public function reconcileStaleRunningJobs('));

// ==========================================================================
// SECTION 14: No regression to prior migrated slices
// ==========================================================================
echo "\nSection 14: No regression to prior migrated slices (appointments, media pilot, sales)\n";

$mediaClientSvc = fileContent($repoRoot, 'system/modules/clients/services/ClientProfileImageService.php');
assertThat('ClientProfileImageService.php exists (MEDIA_PILOT)', $mediaClientSvc !== '');
assertThat('ClientProfileImageService: still has RequestContextHolder (no regression)', str_contains($mediaClientSvc, 'RequestContextHolder'));

$apptSvc = fileContent($repoRoot, 'system/modules/appointments/services/BlockedSlotService.php');
assertThat('BlockedSlotService.php exists (APPOINTMENTS_P1)', $apptSvc !== '');
assertThat('BlockedSlotService: still has RequestContextHolder (no regression)', str_contains($apptSvc, 'RequestContextHolder'));

$invSvc = fileContent($repoRoot, 'system/modules/sales/services/InvoiceService.php');
assertThat('InvoiceService.php exists (SALES_P3)', $invSvc !== '');
assertThat('InvoiceService: still has RequestContextHolder (no regression)', str_contains($invSvc, 'RequestContextHolder'));

$paySvc = fileContent($repoRoot, 'system/modules/sales/services/PaymentService.php');
assertThat('PaymentService.php exists (SALES_P3)', $paySvc !== '');
assertThat('PaymentService: still has RequestContextHolder (no regression)', str_contains($paySvc, 'RequestContextHolder'));

// ==========================================================================
// SUMMARY
// ==========================================================================
echo "\n" . str_repeat('=', 72) . "\n";
echo "BIG-07 verification complete\n";
echo str_repeat('-', 72) . "\n";
echo "PASSED: {$passed}\n";
echo "FAILED: {$failed}\n";

if ($errors !== []) {
    echo "\nFailed assertions:\n";
    foreach ($errors as $e) {
        echo '  ' . $e . "\n";
    }
}

$total = $passed + $failed;
echo "\n";

if ($failed === 0) {
    echo "RESULT: PASS — {$passed}/{$total} assertions passed.\n";
    exit(0);
} else {
    echo "RESULT: FAIL — {$failed}/{$total} assertions failed.\n";
    exit(1);
}
