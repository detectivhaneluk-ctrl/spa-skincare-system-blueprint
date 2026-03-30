<?php

declare(strict_types=1);

/**
 * CLOSURE-20 / 20B / 20C / 20D: platform.organizations.manage POST barrier inventory + static contracts (no DB).
 *
 * All canonical platformManageMw mutation handlers must use operator password step-up except support-entry (separate seam).
 * Registry create/update: assertManageCsrf + requirePlatformManagePasswordStepUp (20D policy).
 */
$system = dirname(__DIR__, 2);
$ctrlDir = $system . '/modules/organizations/controllers';

$auth = (string) file_get_contents($system . '/core/auth/AuthService.php');
$guard = (string) file_get_contents($system . '/modules/organizations/services/FounderSafeActionGuardrailService.php');
$boot = (string) file_get_contents($system . '/modules/bootstrap/register_organizations.php');
$partial = (string) file_get_contents($system . '/modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php');
$previewSvc = (string) file_get_contents($system . '/modules/organizations/services/FounderSafeActionPreviewService.php');
$safePreviewView = (string) file_get_contents($system . '/modules/organizations/views/platform_control_plane/safe_action_preview.php');
$archiveConfirm = (string) file_get_contents($system . '/modules/organizations/views/platform_salons/archive_confirm.php');
$salonCreate = (string) file_get_contents($system . '/modules/organizations/views/platform_salons/create.php');
$registryCreate = (string) file_get_contents($system . '/modules/organizations/views/platform-registry/create.php');
$orgRegistryRoutes = (string) file_get_contents($system . '/routes/web/register_platform_organization_registry.php');

/**
 * @return non-empty-string|null
 */
function platform_barrier_extract_method(string $fileSrc, string $methodName): ?string
{
    $pattern = '/public\s+function\s+' . preg_quote($methodName, '/') . '\b[\s\S]*?(?=\n    public\s+function\s+\w+|\n    private\s+function\s+\w+|\Z)/';
    if (preg_match($pattern, $fileSrc, $m) !== 1) {
        return null;
    }

    return $m[0];
}

/**
 * @param 'platform_manage_step_up'|'support_entry_step_up' $barrier
 */
function platform_barrier_assert(string $relativePath, string $methodName, string $barrier): bool
{
    global $ctrlDir;
    $path = $ctrlDir . '/' . $relativePath;
    if (!is_file($path)) {
        return false;
    }
    $src = (string) file_get_contents($path);
    $body = platform_barrier_extract_method($src, $methodName);
    if ($body === null || $body === '') {
        return false;
    }
    $hasPlatform = str_contains($body, 'requirePlatformManagePasswordStepUp');
    $hasSupport = str_contains($body, 'requireSupportEntryPasswordStepUp');
    $hasRiskKey = str_contains($body, 'FounderActionRiskPolicy::');

    return match ($barrier) {
        'platform_manage_step_up' => $hasPlatform && !$hasSupport && $hasRiskKey,
        'support_entry_step_up' => $hasSupport
            && str_contains($body, 'requireSupportEntryControlPlaneMfa')
            && strpos($body, 'requireSupportEntryPasswordStepUp') < strpos($body, 'requireSupportEntryControlPlaneMfa')
            && strpos($body, 'requireSupportEntryControlPlaneMfa') < strpos($body, 'startForFounderActor'),
    };
}

/** @var list<array{0:string,1:string,2:string}> */
$inventory = [
    ['PlatformTenantAccessController.php', 'postRepair', 'platform_manage_step_up'],
    ['PlatformTenantAccessController.php', 'postUserActivate', 'platform_manage_step_up'],
    ['PlatformTenantAccessController.php', 'postUserDeactivate', 'platform_manage_step_up'],
    ['PlatformTenantAccessController.php', 'postMembershipSuspend', 'platform_manage_step_up'],
    ['PlatformTenantAccessController.php', 'postMembershipUnsuspend', 'platform_manage_step_up'],
    ['PlatformTenantAccessController.php', 'postCanonicalizePlatformPrincipal', 'platform_manage_step_up'],
    ['PlatformTenantAccessController.php', 'postProvisionAdmin', 'platform_manage_step_up'],
    ['PlatformTenantAccessController.php', 'postProvisionStaff', 'platform_manage_step_up'],
    ['PlatformSalonAdminAccessController.php', 'emailPost', 'platform_manage_step_up'],
    ['PlatformSalonAdminAccessController.php', 'passwordPost', 'platform_manage_step_up'],
    ['PlatformSalonAdminAccessController.php', 'disableLoginPost', 'platform_manage_step_up'],
    ['PlatformSalonAdminAccessController.php', 'enableLoginPost', 'platform_manage_step_up'],
    ['PlatformFounderGuidedRepairController.php', 'postBlockedUserWizard', 'platform_manage_step_up'],
    ['PlatformFounderGuidedRepairController.php', 'postOrgRecoveryWizard', 'platform_manage_step_up'],
    ['PlatformFounderSecurityController.php', 'postPublicSurfaceKillSwitches', 'platform_manage_step_up'],
    ['PlatformFounderSupportEntryController.php', 'postStart', 'support_entry_step_up'],
    ['PlatformOrganizationRegistryManageController.php', 'suspend', 'platform_manage_step_up'],
    ['PlatformOrganizationRegistryManageController.php', 'reactivate', 'platform_manage_step_up'],
    ['PlatformOrganizationRegistryManageController.php', 'store', 'platform_manage_step_up'],
    ['PlatformOrganizationRegistryManageController.php', 'update', 'platform_manage_step_up'],
    ['PlatformSalonLifecycleController.php', 'archive', 'platform_manage_step_up'],
    ['PlatformSalonLifecycleController.php', 'store', 'platform_manage_step_up'],
    ['PlatformSalonLifecycleController.php', 'update', 'platform_manage_step_up'],
    ['PlatformGlobalBranchController.php', 'deactivate', 'platform_manage_step_up'],
    ['PlatformGlobalBranchController.php', 'store', 'platform_manage_step_up'],
    ['PlatformGlobalBranchController.php', 'update', 'platform_manage_step_up'],
    ['PlatformSalonPeopleController.php', 'store', 'platform_manage_step_up'],
    ['PlatformSalonBranchController.php', 'store', 'platform_manage_step_up'],
    ['PlatformSalonBranchController.php', 'update', 'platform_manage_step_up'],
];

$orgRegistryStore = (string) file_get_contents($ctrlDir . '/PlatformOrganizationRegistryManageController.php');
$registryStoreHasCsrf = str_contains($orgRegistryStore, 'public function store')
    && preg_match('/public\s+function\s+store\b[\s\S]*?assertManageCsrf[\s\S]*?requirePlatformManagePasswordStepUp\s*\(\s*\$actor\s*,\s*FounderActionRiskPolicy::[\s\S]*?createOrganization/s', $orgRegistryStore) === 1;

$checks = [
    'AuthService defines platform_manage_stepup throttle + assert + verify' => str_contains($auth, 'platform_manage_stepup:')
        && str_contains($auth, 'function assertPlatformManagePasswordStepUpAllowed')
        && str_contains($auth, 'function verifyPasswordForPlatformManageStepUp'),
    'AuthService defines account_password_change throttle + assert (account password POST hardening)' => str_contains($auth, 'account_password_change:')
        && str_contains($auth, 'function assertAccountPasswordChangeAllowed'),
    'register_platform_organization_registry manageMw includes PlatformManagePostRateLimitMiddleware (parity with /platform-admin/*)' => str_contains($orgRegistryRoutes, 'use Core\\Middleware\\PlatformManagePostRateLimitMiddleware;')
        && str_contains($orgRegistryRoutes, 'PlatformManagePostRateLimitMiddleware::class'),
    'FounderSafeActionGuardrailService password + risk policy + TOTP field + requirePlatformManagePasswordStepUp(action key)' => str_contains($guard, 'PLATFORM_MANAGE_PASSWORD_CONFIRM_FIELD')
        && str_contains($guard, 'platform_manage_password_confirm')
        && str_contains($guard, 'PLATFORM_CONTROL_PLANE_TOTP_FIELD')
        && str_contains($guard, 'FounderActionRiskPolicy')
        && str_contains($guard, 'ControlPlaneTotpService')
        && str_contains($guard, 'founder_step_up_password_verified')
        && str_contains($guard, 'function requirePlatformManagePasswordStepUp')
        && preg_match('/function\s+requirePlatformManagePasswordStepUp\s*\(\s*int\s+\$\w+\s*,\s*string\s+\$actionKey\s*=/s', $guard) === 1
        && str_contains($guard, 'verifyPasswordForPlatformManageStepUp')
        && !str_contains($guard, 'founder_mfa_skipped_not_enrolled')
        && str_contains($guard, 'requireControlPlaneTotpVerifiedForActor')
        && str_contains($guard, 'founder_mfa_denied_missing_enrollment'),
    'platform_manage_password_step_up_field partial' => str_contains($partial, 'PLATFORM_MANAGE_PASSWORD_CONFIRM_FIELD')
        && str_contains($partial, 'type="password"')
        && str_contains($partial, 'PLATFORM_CONTROL_PLANE_TOTP_FIELD'),
    'FounderSafeActionPreviewService require_platform_manage_password_step_up: org suspend/reactivate, branch deactivate, access flows, kill switches' => preg_match("/buildOrgSuspendPreview[\s\S]*?'require_platform_manage_password_step_up'\s*=>\s*true/", $previewSvc) === 1
        && preg_match("/buildOrgReactivatePreview[\s\S]*?'require_platform_manage_password_step_up'\s*=>\s*true/", $previewSvc) === 1
        && preg_match("/buildBranchDeactivatePreview[\s\S]*?'require_platform_manage_password_step_up'\s*=>\s*true/", $previewSvc) === 1
        && preg_match("/buildAccessRepairPreview[\s\S]*?'require_platform_manage_password_step_up'\s*=>\s*true/", $previewSvc) === 1
        && preg_match("/buildUserActivatePreview[\s\S]*?'require_platform_manage_password_step_up'\s*=>\s*true/", $previewSvc) === 1
        && preg_match("/buildUserDeactivatePreview[\s\S]*?'require_platform_manage_password_step_up'\s*=>\s*true/", $previewSvc) === 1
        && preg_match("/buildKillSwitchPreview[\s\S]*?'require_platform_manage_password_step_up'\s*=>\s*true/", $previewSvc) === 1,
    'safe_action_preview renders platform step-up partial when require_platform_manage_password_step_up' => str_contains($safePreviewView, 'require_platform_manage_password_step_up')
        && str_contains($safePreviewView, 'platform_manage_password_step_up_field.php'),
    'archive_confirm includes platform_manage_password_step_up_field partial' => str_contains($archiveConfirm, 'platform_manage_password_step_up_field.php'),
    'salon create + registry create forms include platform_manage_password_step_up_field partial' => str_contains($salonCreate, 'platform_manage_password_step_up_field.php')
        && str_contains($registryCreate, 'platform_manage_password_step_up_field.php'),
    'PlatformOrganizationRegistryManageController::store assertManageCsrf then password step-up then createOrganization' => $registryStoreHasCsrf,
    'PlatformFounderGuidedRepairController wired with FounderSafeActionGuardrailService in bootstrap' => str_contains($boot, 'PlatformFounderGuidedRepairController')
        && str_contains($boot, 'FounderSafeActionGuardrailService::class'),
    'PlatformSalonPeopleController wired with FounderSafeActionGuardrailService in bootstrap' => str_contains($boot, 'PlatformSalonPeopleController')
        && preg_match('/PlatformSalonPeopleController[\s\S]*?FounderSafeActionGuardrailService::class/s', $boot) === 1,
    'PlatformSalonBranchController wired with FounderSafeActionGuardrailService in bootstrap' => str_contains($boot, 'PlatformSalonBranchController')
        && preg_match('/PlatformSalonBranchController[\s\S]*?FounderSafeActionGuardrailService::class/s', $boot) === 1,
];

$barrierLabel = static function (string $code): string {
    return match ($code) {
        'platform_manage_step_up' => 'platform_manage_password_step_up',
        'support_entry_step_up' => 'support_entry_password_step_up',
    };
};

foreach ($inventory as [$file, $method, $barrier]) {
    $ok = platform_barrier_assert($file, $method, $barrier);
    $label = 'HANDLER ' . substr($file, 0, -4) . '::' . $method . ' barrier=' . $barrierLabel($barrier);
    $checks[$label] = $ok;
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    if (str_starts_with($label, 'HANDLER ')) {
        continue;
    }
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
