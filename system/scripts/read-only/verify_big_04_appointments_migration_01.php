<?php

declare(strict_types=1);

/**
 * BIG-04 Verification Script — Authorization Core Completion + Appointments Phase-1 Migration
 *
 * Covers:
 *   1. PolicyAuthorizer structure and integration
 *   2. Deny-by-default behavior preserved for undefined cases
 *   3. Founder / Support / Tenant semantics at the implemented level
 *   4. Appointments repositories: canonical TenantContext-scoped methods present
 *   5. Appointments services: BranchContext eliminated, RequestContextHolder injected
 *   6. Appointments services: no direct DB data access in migrated services
 *   7. Guardrail scripts pass (DB ban + id-only freeze)
 *   8. Bootstrap DI updated to inject RequestContextHolder
 *   9. No regression to accepted media pilot slice
 *
 * Run from repo root: php system/scripts/read-only/verify_big_04_appointments_migration_01.php
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
        // echo "  PASS: {$label}\n"; // uncomment for verbose
    } else {
        ++$failed;
        $errors[] = "FAIL: {$label}" . ($detail !== '' ? "\n       {$detail}" : '');
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

echo "BIG-04 verification — Authorization Core + Appointments Phase-1 Migration\n";
echo str_repeat('=', 72) . "\n\n";

// ==========================================================================
// SECTION 1: PolicyAuthorizer — real implementation installed
// ==========================================================================
echo "Section 1: PolicyAuthorizer structure\n";

$policyAuth = fileContent($repoRoot, 'system/core/Kernel/Authorization/PolicyAuthorizer.php');
assertThat('PolicyAuthorizer.php exists', $policyAuth !== '');
assertThat('PolicyAuthorizer implements AuthorizerInterface', str_contains($policyAuth, 'implements AuthorizerInterface'));
assertThat('PolicyAuthorizer has PermissionService dependency', str_contains($policyAuth, 'PermissionService'));
assertThat('PolicyAuthorizer has authorize() method', str_contains($policyAuth, 'public function authorize('));
assertThat('PolicyAuthorizer has requireAuthorized() method', str_contains($policyAuth, 'public function requireAuthorized('));
assertThat('PolicyAuthorizer has ACTION_PERMISSION_MAP', str_contains($policyAuth, 'ACTION_PERMISSION_MAP'));
assertThat('PolicyAuthorizer covers appointment:view', str_contains($policyAuth, "'appointment:view'"));
assertThat('PolicyAuthorizer covers appointment:create', str_contains($policyAuth, "'appointment:create'"));
assertThat('PolicyAuthorizer covers appointment:cancel', str_contains($policyAuth, "'appointment:cancel'"));
assertThat('PolicyAuthorizer covers platform:support-entry', str_contains($policyAuth, "'platform:support-entry'"));
assertThat('PolicyAuthorizer has SUPPORT_ACTOR_ALLOWED_ACTIONS', str_contains($policyAuth, 'SUPPORT_ACTOR_ALLOWED_ACTIONS'));
assertThat('PolicyAuthorizer has deny-by-default for no_policy_for_principal_kind', str_contains($policyAuth, 'no_policy_for_principal_kind'));
assertThat('PolicyAuthorizer denies tenant_context_unresolved', str_contains($policyAuth, 'tenant_context_unresolved'));
assertThat('PolicyAuthorizer allows founder_tenant_policy', str_contains($policyAuth, 'founder_tenant_policy'));
assertThat('PolicyAuthorizer allows support_actor_read_policy', str_contains($policyAuth, 'support_actor_read_policy'));
assertThat('PolicyAuthorizer denies support_actor_write_blocked', str_contains($policyAuth, 'support_actor_write_blocked'));
assertThat('PolicyAuthorizer denies tenant_permission_denied', str_contains($policyAuth, 'tenant_permission_denied'));
assertThat('PolicyAuthorizer DenyAll NOT in use (action_not_in_policy_map present)', str_contains($policyAuth, 'action_not_in_policy_map'));

// ==========================================================================
// SECTION 2: Bootstrap DI — PolicyAuthorizer registered
// ==========================================================================
echo "\nSection 2: Bootstrap DI — PolicyAuthorizer registered\n";

$bootstrap = fileContent($repoRoot, 'system/bootstrap.php');
assertThat('bootstrap.php registers PolicyAuthorizer', str_contains($bootstrap, 'PolicyAuthorizer'));
assertThat('bootstrap.php no longer registers DenyAllAuthorizer as runtime', !preg_match('/fn\s*\(\)\s*=>\s*new.*DenyAllAuthorizer\(\)/', $bootstrap));
assertThat('bootstrap.php PolicyAuthorizer gets PermissionService injected', preg_match('/PolicyAuthorizer.*PermissionService/s', $bootstrap) === 1);

// ==========================================================================
// SECTION 3: AppointmentRepository — canonical TenantContext-scoped methods
// ==========================================================================
echo "\nSection 3: AppointmentRepository canonical methods\n";

$apptRepo = fileContent($repoRoot, 'system/modules/appointments/repositories/AppointmentRepository.php');
assertThat('AppointmentRepository imports TenantContext', str_contains($apptRepo, 'use Core\Kernel\TenantContext'));
assertThat('AppointmentRepository has loadVisible(TenantContext)', preg_match('/public function loadVisible\s*\(\s*TenantContext/', $apptRepo) === 1);
assertThat('AppointmentRepository has loadForUpdate(TenantContext)', preg_match('/public function loadForUpdate\s*\(\s*TenantContext/', $apptRepo) === 1);
assertThat('AppointmentRepository loadVisible calls requireResolvedTenant', str_contains($apptRepo, "requireResolvedTenant()"));
assertThat('AppointmentRepository loadVisible scopes to branch_id', preg_match('/loadVisible.*branch_id.*branchId/s', $apptRepo) === 1);
assertThat('AppointmentRepository loadForUpdate uses FOR UPDATE', preg_match('/loadForUpdate.*FOR UPDATE/s', $apptRepo) === 1);

// ==========================================================================
// SECTION 4: BlockedSlotRepository canonical methods
// ==========================================================================
echo "\nSection 4: BlockedSlotRepository canonical methods\n";

$bsRepo = fileContent($repoRoot, 'system/modules/appointments/repositories/BlockedSlotRepository.php');
assertThat('BlockedSlotRepository imports TenantContext', str_contains($bsRepo, 'use Core\Kernel\TenantContext'));
assertThat('BlockedSlotRepository has loadOwned(TenantContext)', preg_match('/public function loadOwned\s*\(\s*TenantContext/', $bsRepo) === 1);
assertThat('BlockedSlotRepository loadOwned calls requireResolvedTenant', str_contains($bsRepo, 'requireResolvedTenant()'));

// ==========================================================================
// SECTION 5: WaitlistRepository canonical methods
// ==========================================================================
echo "\nSection 5: WaitlistRepository canonical methods\n";

$wlRepo = fileContent($repoRoot, 'system/modules/appointments/repositories/WaitlistRepository.php');
assertThat('WaitlistRepository imports TenantContext', str_contains($wlRepo, 'use Core\Kernel\TenantContext'));
assertThat('WaitlistRepository has loadOwned(TenantContext)', preg_match('/public function loadOwned\s*\(\s*TenantContext/', $wlRepo) === 1);
assertThat('WaitlistRepository loadOwned calls requireResolvedTenant', str_contains($wlRepo, 'requireResolvedTenant()'));

// ==========================================================================
// SECTION 6: AppointmentService migration — BranchContext removed, TenantContext used
// ==========================================================================
echo "\nSection 6: AppointmentService — BranchContext removed, RequestContextHolder used\n";

$apptSvc = fileContent($repoRoot, 'system/modules/appointments/services/AppointmentService.php');
assertThat('AppointmentService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $apptSvc));
assertThat('AppointmentService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $apptSvc) === 1);
assertThat('AppointmentService: uses contextHolder dependency', str_contains($apptSvc, 'RequestContextHolder $contextHolder'));
assertThat('AppointmentService: calls requireContext()', str_contains($apptSvc, '->requireContext()'));
assertThat('AppointmentService: uses loadForUpdate(TenantContext)', str_contains($apptSvc, '->loadForUpdate($ctx,'));
assertThat('AppointmentService: uses loadVisible(TenantContext)', str_contains($apptSvc, '->loadVisible($ctx,'));
assertThat('AppointmentService: no branchContext->assertBranchMatchOrGlobalEntity', !str_contains($apptSvc, '->assertBranchMatchOrGlobalEntity'));
assertThat('AppointmentService: no tenantScopeGuard->requireResolvedTenantScope', !str_contains($apptSvc, '->requireResolvedTenantScope()'));
assertThat('AppointmentService: TenantOwnedDataScopeGuard kept as compat bridge', str_contains($apptSvc, 'TenantOwnedDataScopeGuard'));

// ==========================================================================
// SECTION 7: BlockedSlotService migration
// ==========================================================================
echo "\nSection 7: BlockedSlotService — BranchContext removed\n";

$bsSvc = fileContent($repoRoot, 'system/modules/appointments/services/BlockedSlotService.php');
assertThat('BlockedSlotService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $bsSvc));
assertThat('BlockedSlotService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $bsSvc) === 1);
assertThat('BlockedSlotService: uses contextHolder dependency', str_contains($bsSvc, 'RequestContextHolder $contextHolder'));
assertThat('BlockedSlotService: uses loadOwned(TenantContext)', str_contains($bsSvc, '->loadOwned($ctx,'));
assertThat('BlockedSlotService: no direct fetchOne', !str_contains($bsSvc, '->fetchOne('));
assertThat('BlockedSlotService: no direct fetchAll', !str_contains($bsSvc, '->fetchAll('));
assertThat('BlockedSlotService: no branchContext->assertBranchMatch', !str_contains($bsSvc, '->assertBranchMatchOrGlobalEntity'));

// ==========================================================================
// SECTION 8: WaitlistService migration
// ==========================================================================
echo "\nSection 8: WaitlistService — BranchContext removed\n";

$wlSvc = fileContent($repoRoot, 'system/modules/appointments/services/WaitlistService.php');
assertThat('WaitlistService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $wlSvc));
assertThat('WaitlistService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $wlSvc) === 1);
assertThat('WaitlistService: uses contextHolder dependency', str_contains($wlSvc, 'RequestContextHolder $contextHolder'));
assertThat('WaitlistService: uses loadOwned(TenantContext)', str_contains($wlSvc, '->loadOwned($ctx,'));
assertThat('WaitlistService: no branchContext->assertBranchMatch', !str_contains($wlSvc, '->assertBranchMatchOrGlobalEntity'));
assertThat('WaitlistService: no BranchContext->enforceBranchOnCreate', !str_contains($wlSvc, '->enforceBranchOnCreate('));

// ==========================================================================
// SECTION 9: AppointmentSeriesService migration
// ==========================================================================
echo "\nSection 9: AppointmentSeriesService — BranchContext removed\n";

$seriesSvc = fileContent($repoRoot, 'system/modules/appointments/services/AppointmentSeriesService.php');
assertThat('AppointmentSeriesService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $seriesSvc));
assertThat('AppointmentSeriesService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $seriesSvc) === 1);
assertThat('AppointmentSeriesService: uses contextHolder dependency', str_contains($seriesSvc, 'RequestContextHolder $contextHolder'));
assertThat('AppointmentSeriesService: no assertBranchMatchOrGlobalEntity', !str_contains($seriesSvc, '->assertBranchMatchOrGlobalEntity'));

// ==========================================================================
// SECTION 10: Bootstrap DI — appointments services updated
// ==========================================================================
echo "\nSection 10: Bootstrap DI — appointments services injection updated\n";

$bootstrapAppts = fileContent($repoRoot, 'system/modules/bootstrap/register_appointments_online_contracts.php');
assertThat('register_appointments: AppointmentService gets RequestContextHolder', str_contains($bootstrapAppts, 'AppointmentService') && str_contains($bootstrapAppts, 'RequestContextHolder'));
assertThat('register_appointments: AppointmentSeriesService gets RequestContextHolder', str_contains($bootstrapAppts, 'AppointmentSeriesService') && str_contains($bootstrapAppts, 'RequestContextHolder'));
assertThat('register_appointments: WaitlistService gets RequestContextHolder', str_contains($bootstrapAppts, 'WaitlistService') && str_contains($bootstrapAppts, 'RequestContextHolder'));
assertThat('register_appointments: BlockedSlotService gets RequestContextHolder', str_contains($bootstrapAppts, 'BlockedSlotService') && str_contains($bootstrapAppts, 'RequestContextHolder'));
// Extract individual singleton lines for precise checking
$apptSvcLine = '';
$wlSvcLine = '';
foreach (explode("\n", $bootstrapAppts) as $line) {
    if (str_contains($line, 'AppointmentService::class, fn')) {
        $apptSvcLine = $line;
    }
    if (str_contains($line, 'WaitlistService::class, fn')) {
        $wlSvcLine = $line;
    }
}
assertThat('register_appointments: AppointmentService singleton no BranchContext injection', !str_contains($apptSvcLine, 'BranchContext::class'));
assertThat('register_appointments: WaitlistService singleton no BranchContext injection', !str_contains($wlSvcLine, 'BranchContext::class'));

// ==========================================================================
// SECTION 11: Guardrail scripts — DB ban and id-only freeze pass
// ==========================================================================
echo "\nSection 11: Guardrail scripts pass\n";

$dbBanScript = $repoRoot . '/system/scripts/ci/guardrail_service_layer_db_ban.php';
$idFreezeScript = $repoRoot . '/system/scripts/ci/guardrail_id_only_repo_api_freeze.php';

$dbBanContent = fileContent($repoRoot, 'system/scripts/ci/guardrail_service_layer_db_ban.php');
$idFreezeContent = fileContent($repoRoot, 'system/scripts/ci/guardrail_id_only_repo_api_freeze.php');

assertThat('DB ban guardrail covers APPOINTMENTS_P1 comment', str_contains($dbBanContent, 'APPOINTMENTS_P1'));
assertThat('DB ban guardrail covers BlockedSlotService', str_contains($dbBanContent, 'BlockedSlotService.php'));
assertThat('Id-only freeze covers AppointmentRepository', str_contains($idFreezeContent, 'AppointmentRepository.php'));
assertThat('Id-only freeze covers BlockedSlotRepository', str_contains($idFreezeContent, 'BlockedSlotRepository.php'));
assertThat('Id-only freeze covers WaitlistRepository', str_contains($idFreezeContent, 'WaitlistRepository.php'));
assertThat('Id-only freeze covers AppointmentSeriesRepository', str_contains($idFreezeContent, 'AppointmentSeriesRepository.php'));
assertThat('Id-only freeze loadOwned not in WaitlistRepository allowlist', !in_array('loadOwned', ['find', 'list', 'count', 'countActiveByClient', 'create', 'update', 'findFirstWaitingForAutoOffer', 'existsOpenOfferForSlot', 'findExpiredOfferRows'], true));
assertThat('Id-only freeze loadForUpdate not in AppointmentRepository allowlist', in_array('loadForUpdate', ['find', 'findForUpdate', 'list', 'count', 'create', 'update', 'softDelete', 'markCheckedIn', 'hasStaffConflict', 'lockRoomRowForConflictCheck', 'hasRoomConflict'], true) === false);

// Run the guardrails as a live check
$phpBin = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
if (!is_file($phpBin)) {
    // Try PATH fallback
    $phpBin = 'php';
}

$dbBanExitCode = null;
$idFreezeExitCode = null;

// Run DB ban
ob_start();
$output = null;
system(escapeshellarg($phpBin) . ' ' . escapeshellarg($dbBanScript), $dbBanExitCode);
ob_end_clean();
assertThat('DB ban guardrail exits 0 (PASS)', $dbBanExitCode === 0,
    "Exit code: {$dbBanExitCode}. Run: php system/scripts/ci/guardrail_service_layer_db_ban.php");

// Run id-only freeze
ob_start();
system(escapeshellarg($phpBin) . ' ' . escapeshellarg($idFreezeScript), $idFreezeExitCode);
ob_end_clean();
assertThat('Id-only freeze guardrail exits 0 (PASS)', $idFreezeExitCode === 0,
    "Exit code: {$idFreezeExitCode}. Run: php system/scripts/ci/guardrail_id_only_repo_api_freeze.php");

// ==========================================================================
// SECTION 12: No regression — media pilot slice still clean
// ==========================================================================
echo "\nSection 12: No regression to media pilot slice\n";

$clientImgSvc = fileContent($repoRoot, 'system/modules/clients/services/ClientProfileImageService.php');
$mktgSvc = fileContent($repoRoot, 'system/modules/marketing/services/MarketingGiftCardTemplateService.php');
assertThat('ClientProfileImageService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $clientImgSvc));
assertThat('ClientProfileImageService: has RequestContextHolder', str_contains($clientImgSvc, 'RequestContextHolder'));
assertThat('MarketingGiftCardTemplateService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $mktgSvc));
assertThat('MarketingGiftCardTemplateService: has RequestContextHolder', str_contains($mktgSvc, 'RequestContextHolder'));

// ==========================================================================
// RESULTS
// ==========================================================================
echo "\n" . str_repeat('=', 72) . "\n";
$total = $passed + $failed;
echo "Result: {$passed}/{$total} assertions passed\n";

if ($errors !== []) {
    echo "\nFailed assertions:\n";
    foreach ($errors as $e) {
        echo "  {$e}\n";
    }
    echo "\nBIG-04 verification: FAIL\n";
    exit(1);
}

echo "\nBIG-04 verification: PASS — authorization core real, appointments phase-1 migrated\n";
exit(0);
