<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\PlatformManagePostRateLimitMiddleware;
use Core\Middleware\PlatformPrincipalMiddleware;
use Core\Middleware\PermissionMiddleware;
use Modules\Organizations\Controllers\PlatformControlPlaneController;
use Modules\Organizations\Controllers\PlatformFounderBillingShellController;
use Modules\Organizations\Controllers\PlatformFounderGuidedRepairController;
use Modules\Organizations\Controllers\PlatformFounderGuideController;
use Modules\Organizations\Controllers\PlatformFounderIncidentCenterController;
use Modules\Organizations\Controllers\PlatformFounderPlatformShellController;
use Modules\Organizations\Controllers\PlatformFounderSafeActionController;
use Modules\Organizations\Controllers\PlatformFounderSecurityController;
use Modules\Organizations\Controllers\PlatformGlobalBranchController;
use Modules\Organizations\Controllers\PlatformFounderSupportEntryController;
use Modules\Organizations\Controllers\PlatformOrganizationRegistryController;
use Modules\Organizations\Controllers\PlatformOrganizationRegistryManageController;
use Modules\Organizations\Controllers\PlatformSalonController;
use Modules\Organizations\Controllers\PlatformSalonLifecycleController;
use Modules\Organizations\Controllers\PlatformSalonAdminAccessController;
use Modules\Organizations\Controllers\PlatformSalonBranchController;
use Modules\Organizations\Controllers\PlatformSalonPeopleController;
use Modules\Organizations\Controllers\PlatformTenantAccessController;

$platformMw = [AuthMiddleware::class, PlatformPrincipalMiddleware::class, PermissionMiddleware::for('platform.organizations.view')];
$platformManageMw = [AuthMiddleware::class, PlatformPrincipalMiddleware::class, PermissionMiddleware::for('platform.organizations.manage'), PlatformManagePostRateLimitMiddleware::class];

$router->get('/platform-admin', [PlatformControlPlaneController::class, 'index'], $platformMw);
$router->get('/platform-admin/salons/create', [PlatformSalonLifecycleController::class, 'create'], $platformManageMw);
$router->post('/platform-admin/salons', [PlatformSalonLifecycleController::class, 'store'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/edit', [PlatformSalonLifecycleController::class, 'edit'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/suspend-confirm', [PlatformSalonLifecycleController::class, 'suspendPreview'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/reactivate-confirm', [PlatformSalonLifecycleController::class, 'reactivatePreview'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/archive-confirm', [PlatformSalonLifecycleController::class, 'archiveConfirm'], $platformManageMw);
$router->post('/platform-admin/salons/{id:\d+}/archive', [PlatformSalonLifecycleController::class, 'archive'], $platformManageMw);
$router->post('/platform-admin/salons/{id:\d+}', [PlatformSalonLifecycleController::class, 'update'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/admin-access/email', [PlatformSalonAdminAccessController::class, 'emailForm'], $platformManageMw);
$router->post('/platform-admin/salons/{id:\d+}/admin-access/email', [PlatformSalonAdminAccessController::class, 'emailPost'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/admin-access/password', [PlatformSalonAdminAccessController::class, 'passwordForm'], $platformManageMw);
$router->post('/platform-admin/salons/{id:\d+}/admin-access/password', [PlatformSalonAdminAccessController::class, 'passwordPost'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/admin-access/disable-login-confirm', [PlatformSalonAdminAccessController::class, 'disableLoginConfirm'], $platformManageMw);
$router->post('/platform-admin/salons/{id:\d+}/admin-access/disable-login', [PlatformSalonAdminAccessController::class, 'disableLoginPost'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/admin-access/enable-login-confirm', [PlatformSalonAdminAccessController::class, 'enableLoginConfirm'], $platformManageMw);
$router->post('/platform-admin/salons/{id:\d+}/admin-access/enable-login', [PlatformSalonAdminAccessController::class, 'enableLoginPost'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/branches/create', [PlatformSalonBranchController::class, 'createForm'], $platformManageMw);
$router->post('/platform-admin/salons/{id:\d+}/branches', [PlatformSalonBranchController::class, 'store'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/branches/{branchId:\d+}/edit', [PlatformSalonBranchController::class, 'editForm'], $platformManageMw);
$router->post('/platform-admin/salons/{id:\d+}/branches/{branchId:\d+}', [PlatformSalonBranchController::class, 'update'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}/people/create', [PlatformSalonPeopleController::class, 'createForm'], $platformManageMw);
$router->post('/platform-admin/salons/{id:\d+}/people', [PlatformSalonPeopleController::class, 'store'], $platformManageMw);
$router->get('/platform-admin/salons/{id:\d+}', [PlatformSalonController::class, 'show'], $platformMw);
$router->get('/platform-admin/salons', [PlatformSalonController::class, 'index'], $platformMw);
$router->get('/platform-admin/billing', [PlatformFounderBillingShellController::class, 'index'], $platformMw);
$router->get('/platform-admin/problems', [PlatformFounderIncidentCenterController::class, 'problems'], $platformMw);
$router->get('/platform-admin/system', [PlatformFounderPlatformShellController::class, 'index'], $platformMw);
$router->get('/platform-admin/platform', static function (): void {
    header('Location: /platform-admin/system', true, 302);
    exit;
}, $platformMw);
$router->get('/platform-admin/guide', [PlatformFounderGuideController::class, 'index'], $platformMw);
$router->get('/platform-admin/incidents', [PlatformFounderIncidentCenterController::class, 'index'], $platformMw);

$router->get('/platform-admin/safe-actions/organizations/{id:\d+}/suspend-preview', [PlatformFounderSafeActionController::class, 'orgSuspendPreview'], $platformManageMw);
$router->get('/platform-admin/safe-actions/organizations/{id:\d+}/reactivate-preview', [PlatformFounderSafeActionController::class, 'orgReactivatePreview'], $platformManageMw);
$router->get('/platform-admin/safe-actions/access/{id:\d+}/user-deactivate-preview', [PlatformFounderSafeActionController::class, 'userDeactivatePreview'], $platformManageMw);
$router->get('/platform-admin/safe-actions/access/{id:\d+}/user-activate-preview', [PlatformFounderSafeActionController::class, 'userActivatePreview'], $platformManageMw);
$router->get('/platform-admin/safe-actions/access/{id:\d+}/repair-preview', [PlatformFounderSafeActionController::class, 'accessRepairPreview'], $platformManageMw);
$router->get('/platform-admin/safe-actions/branches/{id:\d+}/deactivate-preview', [PlatformFounderSafeActionController::class, 'branchDeactivatePreview'], $platformManageMw);
$router->get('/platform-admin/safe-actions/security/kill-switches-preview', [PlatformFounderSafeActionController::class, 'killSwitchesPreview'], $platformManageMw);
$router->get('/platform-admin/safe-actions/support-entry/preview', [PlatformFounderSafeActionController::class, 'supportEntryPreview'], $platformManageMw);

$router->get('/platform-admin/organizations/create', [PlatformOrganizationRegistryManageController::class, 'create'], $platformManageMw);
$router->post('/platform-admin/organizations', [PlatformOrganizationRegistryManageController::class, 'store'], $platformManageMw);
$router->get('/platform-admin/organizations/{id:\d+}/edit', [PlatformOrganizationRegistryManageController::class, 'edit'], $platformManageMw);
$router->get('/platform-admin/organizations/{id:\d+}/guided-recovery', [PlatformFounderGuidedRepairController::class, 'orgRecoveryWizard'], $platformMw);
$router->post('/platform-admin/organizations/{id:\d+}/guided-recovery', [PlatformFounderGuidedRepairController::class, 'postOrgRecoveryWizard'], $platformManageMw);
$router->post('/platform-admin/organizations/{id:\d+}/suspend', [PlatformOrganizationRegistryManageController::class, 'suspend'], $platformManageMw);
$router->post('/platform-admin/organizations/{id:\d+}/reactivate', [PlatformOrganizationRegistryManageController::class, 'reactivate'], $platformManageMw);
$router->post('/platform-admin/organizations/{id:\d+}', [PlatformOrganizationRegistryManageController::class, 'update'], $platformManageMw);
$router->get('/platform-admin/organizations/{id:\d+}', [PlatformOrganizationRegistryController::class, 'show'], $platformMw);
$router->get('/platform-admin/organizations', [PlatformOrganizationRegistryController::class, 'index'], $platformMw);

$router->get('/platform-admin/branches/create', [PlatformGlobalBranchController::class, 'createForm'], $platformMw);
$router->get('/platform-admin/branches/{id:\d+}/edit', [PlatformGlobalBranchController::class, 'editForm'], $platformMw);
$router->get('/platform-admin/branches', [PlatformGlobalBranchController::class, 'index'], $platformMw);
$router->post('/platform-admin/branches/{id:\d+}/deactivate', [PlatformGlobalBranchController::class, 'deactivate'], $platformManageMw);
$router->post('/platform-admin/branches/{id:\d+}', [PlatformGlobalBranchController::class, 'update'], $platformManageMw);
$router->post('/platform-admin/branches', [PlatformGlobalBranchController::class, 'store'], $platformManageMw);
$router->get('/platform-admin/security', [PlatformFounderSecurityController::class, 'index'], $platformMw);
$router->post('/platform-admin/security/public-surface', [PlatformFounderSecurityController::class, 'postPublicSurfaceKillSwitches'], $platformManageMw);

$router->get('/platform-admin/access/provision', [PlatformTenantAccessController::class, 'provision'], $platformMw);
$router->get('/platform-admin/access/{id:\d+}/guided-repair/pin', [PlatformFounderGuidedRepairController::class, 'blockedUserWizardPinShortcut'], $platformMw);
$router->get('/platform-admin/access/{id:\d+}/guided-repair', [PlatformFounderGuidedRepairController::class, 'blockedUserWizard'], $platformMw);
$router->post('/platform-admin/access/{id:\d+}/guided-repair', [PlatformFounderGuidedRepairController::class, 'postBlockedUserWizard'], $platformManageMw);
$router->get('/platform-admin/access/{id:\d+}/diagnostics', [PlatformTenantAccessController::class, 'diagnostics'], $platformMw);
$router->get('/platform-admin/access/{id:\d+}', [PlatformTenantAccessController::class, 'show'], $platformMw);
$router->get('/platform-admin/access', [PlatformTenantAccessController::class, 'index'], $platformMw);
$router->get('/platform-admin/tenant-access', [PlatformTenantAccessController::class, 'legacyTenantAccessRedirect'], $platformMw);

$router->post('/platform-admin/access/repair', [PlatformTenantAccessController::class, 'postRepair'], $platformManageMw);
$router->post('/platform-admin/access/user-activate', [PlatformTenantAccessController::class, 'postUserActivate'], $platformManageMw);
$router->post('/platform-admin/access/user-deactivate', [PlatformTenantAccessController::class, 'postUserDeactivate'], $platformManageMw);
$router->post('/platform-admin/access/membership-suspend', [PlatformTenantAccessController::class, 'postMembershipSuspend'], $platformManageMw);
$router->post('/platform-admin/access/membership-unsuspend', [PlatformTenantAccessController::class, 'postMembershipUnsuspend'], $platformManageMw);
$router->post('/platform-admin/access/canonicalize-platform', [PlatformTenantAccessController::class, 'postCanonicalizePlatformPrincipal'], $platformManageMw);
$router->post('/platform-admin/access/provision-admin', [PlatformTenantAccessController::class, 'postProvisionAdmin'], $platformManageMw);
$router->post('/platform-admin/access/provision-staff', [PlatformTenantAccessController::class, 'postProvisionStaff'], $platformManageMw);

$router->post('/platform-admin/tenant-access/repair', [PlatformTenantAccessController::class, 'postRepair'], $platformManageMw);
$router->post('/platform-admin/tenant-access/user-activate', [PlatformTenantAccessController::class, 'postUserActivate'], $platformManageMw);
$router->post('/platform-admin/tenant-access/user-deactivate', [PlatformTenantAccessController::class, 'postUserDeactivate'], $platformManageMw);
$router->post('/platform-admin/tenant-access/membership-suspend', [PlatformTenantAccessController::class, 'postMembershipSuspend'], $platformManageMw);
$router->post('/platform-admin/tenant-access/membership-unsuspend', [PlatformTenantAccessController::class, 'postMembershipUnsuspend'], $platformManageMw);
$router->post('/platform-admin/tenant-access/canonicalize-platform', [PlatformTenantAccessController::class, 'postCanonicalizePlatformPrincipal'], $platformManageMw);
$router->post('/platform-admin/tenant-access/provision-admin', [PlatformTenantAccessController::class, 'postProvisionAdmin'], $platformManageMw);
$router->post('/platform-admin/tenant-access/provision-staff', [PlatformTenantAccessController::class, 'postProvisionStaff'], $platformManageMw);

$router->post('/platform-admin/support-entry/start', [PlatformFounderSupportEntryController::class, 'postStart'], $platformManageMw);
