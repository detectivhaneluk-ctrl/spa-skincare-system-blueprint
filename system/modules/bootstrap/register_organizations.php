<?php

declare(strict_types=1);

$container->singleton(
    \Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository::class,
    fn ($c) => new \Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository($c->get(\Core\App\Database::class))
);
$container->singleton(
    \Modules\Organizations\Services\UserOrganizationMembershipReadService::class,
    fn ($c) => new \Modules\Organizations\Services\UserOrganizationMembershipReadService(
        $c->get(\Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::class,
    fn ($c) => new \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService(
        $c->get(\Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository::class),
        $c->get(\Modules\Organizations\Services\UserOrganizationMembershipReadService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\UserOrganizationMembershipBackfillService::class,
    fn ($c) => new \Modules\Organizations\Services\UserOrganizationMembershipBackfillService(
        $c->get(\Core\App\Database::class),
        $c->get(\Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository::class)
    )
);
$container->singleton(
    \Modules\Organizations\Repositories\OrganizationRegistryReadRepository::class,
    fn ($c) => new \Modules\Organizations\Repositories\OrganizationRegistryReadRepository($c->get(\Core\App\Database::class))
);
$container->singleton(
    \Modules\Organizations\Repositories\OrganizationRegistryMutationRepository::class,
    fn ($c) => new \Modules\Organizations\Repositories\OrganizationRegistryMutationRepository($c->get(\Core\App\Database::class))
);
$container->singleton(
    \Modules\Organizations\Services\OrganizationRegistryReadService::class,
    fn ($c) => new \Modules\Organizations\Services\OrganizationRegistryReadService(
        $c->get(\Modules\Organizations\Repositories\OrganizationRegistryReadRepository::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\OrganizationRegistryMutationService::class,
    fn ($c) => new \Modules\Organizations\Services\OrganizationRegistryMutationService(
        $c->get(\Modules\Organizations\Repositories\OrganizationRegistryMutationRepository::class),
        $c->get(\Modules\Organizations\Repositories\OrganizationRegistryReadRepository::class),
        $c->get(\Modules\Organizations\Repositories\PlatformControlPlaneReadRepository::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformOrganizationRegistryController::class,
    static fn () => new \Modules\Organizations\Controllers\PlatformOrganizationRegistryController()
);
$container->singleton(
    \Modules\Organizations\Repositories\ControlPlaneTotpUserRepository::class,
    fn ($c) => new \Modules\Organizations\Repositories\ControlPlaneTotpUserRepository($c->get(\Core\App\Database::class))
);
$container->singleton(
    \Modules\Organizations\Services\ControlPlaneTotpService::class,
    fn ($c) => new \Modules\Organizations\Services\ControlPlaneTotpService(
        $c->get(\Modules\Organizations\Repositories\ControlPlaneTotpUserRepository::class)
    )
);
$container->singleton(
    \Modules\Organizations\Policies\FounderActionRiskPolicy::class,
    static fn () => new \Modules\Organizations\Policies\FounderActionRiskPolicy()
);
$container->singleton(
    \Modules\Organizations\Services\FounderSafeActionGuardrailService::class,
    fn ($c) => new \Modules\Organizations\Services\FounderSafeActionGuardrailService(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Audit\AuditService::class),
        $c->get(\Modules\Organizations\Policies\FounderActionRiskPolicy::class),
        $c->get(\Modules\Organizations\Services\ControlPlaneTotpService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\FounderSafeActionPreviewService::class,
    fn ($c) => new \Modules\Organizations\Services\FounderSafeActionPreviewService(
        $c->get(\Modules\Organizations\Services\OrganizationRegistryReadService::class),
        $c->get(\Modules\Organizations\Services\FounderImpactExplainerService::class),
        $c->get(\Modules\Organizations\Repositories\PlatformTenantAccessReadRepository::class),
        $c->get(\Core\Auth\UserAccessShapeService::class),
        $c->get(\Modules\Organizations\Services\PlatformFounderSecurityService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformFounderSafeActionController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformFounderSafeActionController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionPreviewService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformOrganizationRegistryManageController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformOrganizationRegistryManageController(
        $c->get(\Modules\Organizations\Services\OrganizationRegistryMutationService::class),
        $c->get(\Modules\Organizations\Services\OrganizationRegistryReadService::class),
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Core\Audit\AuditService::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository::class,
    fn ($c) => new \Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository($c->get(\Core\App\Database::class))
);
$container->singleton(
    \Modules\Organizations\Services\PlatformSalonProblemsService::class,
    static fn () => new \Modules\Organizations\Services\PlatformSalonProblemsService()
);
$container->singleton(
    \Modules\Organizations\Services\PlatformSalonIssuesSectionService::class,
    static fn () => new \Modules\Organizations\Services\PlatformSalonIssuesSectionService()
);
$container->singleton(
    \Modules\Organizations\Services\PlatformSalonAdminAccessService::class,
    fn ($c) => new \Modules\Organizations\Services\PlatformSalonAdminAccessService(
        $c->get(\Core\Auth\UserAccessShapeService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\PlatformSalonRegistryService::class,
    fn ($c) => new \Modules\Organizations\Services\PlatformSalonRegistryService(
        $c->get(\Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository::class),
        $c->get(\Modules\Organizations\Services\PlatformSalonProblemsService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\PlatformSalonDetailService::class,
    fn ($c) => new \Modules\Organizations\Services\PlatformSalonDetailService(
        $c->get(\Modules\Organizations\Services\OrganizationRegistryReadService::class),
        $c->get(\Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository::class),
        $c->get(\Modules\Organizations\Services\PlatformSalonProblemsService::class),
        $c->get(\Modules\Organizations\Services\PlatformSalonIssuesSectionService::class),
        $c->get(\Modules\Organizations\Services\PlatformSalonAdminAccessService::class),
        $c->get(\Core\Auth\PrincipalAccessService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformSalonController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformSalonController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Core\Permissions\PermissionService::class),
        $c->get(\Modules\Organizations\Services\PlatformSalonRegistryService::class),
        $c->get(\Modules\Organizations\Services\PlatformSalonDetailService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformSalonLifecycleController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformSalonLifecycleController(
        $c->get(\Modules\Organizations\Services\OrganizationRegistryMutationService::class),
        $c->get(\Modules\Organizations\Services\OrganizationRegistryReadService::class),
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Core\Permissions\PermissionService::class),
        $c->get(\Core\Audit\AuditService::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionPreviewService::class),
        $c->get(\Modules\Organizations\Repositories\PlatformControlPlaneReadRepository::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformSalonAdminAccessController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformSalonAdminAccessController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Core\Permissions\PermissionService::class),
        $c->get(\Modules\Organizations\Services\OrganizationRegistryReadService::class),
        $c->get(\Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository::class),
        $c->get(\Modules\Organizations\Services\FounderAccessManagementService::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformSalonBranchController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformSalonBranchController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Core\Permissions\PermissionService::class),
        $c->get(\Modules\Organizations\Services\OrganizationRegistryReadService::class),
        $c->get(\Modules\Organizations\Services\PlatformGlobalBranchManagementService::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformSalonPeopleController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformSalonPeopleController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Core\Permissions\PermissionService::class),
        $c->get(\Modules\Organizations\Services\OrganizationRegistryReadService::class),
        $c->get(\Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository::class),
        $c->get(\Modules\Organizations\Services\TenantUserProvisioningService::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformFounderBillingShellController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformFounderBillingShellController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformFounderPlatformShellController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformFounderPlatformShellController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class)
    )
);
$container->singleton(
    \Modules\Organizations\Repositories\PlatformControlPlaneReadRepository::class,
    fn ($c) => new \Modules\Organizations\Repositories\PlatformControlPlaneReadRepository($c->get(\Core\App\Database::class))
);
$container->singleton(
    \Modules\Organizations\Services\PlatformControlPlaneOverviewService::class,
    fn ($c) => new \Modules\Organizations\Services\PlatformControlPlaneOverviewService(
        $c->get(\Modules\Organizations\Repositories\PlatformControlPlaneReadRepository::class),
        $c->get(\Core\Auth\UserAccessShapeService::class),
        $c->get(\Modules\Organizations\Services\PlatformFounderSecurityService::class),
        $c->get(\Modules\Organizations\Repositories\PlatformFounderAuditReadRepository::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\FounderAccessPresenter::class,
    static fn () => new \Modules\Organizations\Services\FounderAccessPresenter()
);
$container->singleton(
    \Modules\Organizations\Services\FounderPagePurposePresenter::class,
    static fn () => new \Modules\Organizations\Services\FounderPagePurposePresenter()
);
$container->singleton(
    \Modules\Organizations\Services\FounderAccessImpactExplainer::class,
    fn ($c) => new \Modules\Organizations\Services\FounderAccessImpactExplainer(
        $c->get(\Modules\Organizations\Services\FounderAccessPresenter::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\FounderImpactExplainerService::class,
    fn ($c) => new \Modules\Organizations\Services\FounderImpactExplainerService(
        $c->get(\Modules\Organizations\Repositories\PlatformControlPlaneReadRepository::class),
        $c->get(\Modules\Organizations\Services\PlatformFounderSecurityService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\FounderIncidentImpactExplainer::class,
    static fn () => new \Modules\Organizations\Services\FounderIncidentImpactExplainer()
);
$container->singleton(
    \Modules\Organizations\Services\FounderIncidentCenterService::class,
    fn ($c) => new \Modules\Organizations\Services\FounderIncidentCenterService(
        $c->get(\Modules\Organizations\Repositories\PlatformControlPlaneReadRepository::class),
        $c->get(\Core\Auth\UserAccessShapeService::class),
        $c->get(\Modules\Organizations\Services\PlatformFounderSecurityService::class),
        $c->get(\Modules\Organizations\Services\FounderIncidentImpactExplainer::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\PlatformFounderIssuesInboxService::class,
    fn ($c) => new \Modules\Organizations\Services\PlatformFounderIssuesInboxService(
        $c->get(\Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository::class),
        $c->get(\Modules\Organizations\Services\PlatformSalonProblemsService::class),
        $c->get(\Modules\Organizations\Services\PlatformSalonAdminAccessService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformFounderIncidentCenterController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformFounderIncidentCenterController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Modules\Organizations\Services\FounderIncidentCenterService::class),
        $c->get(\Modules\Organizations\Services\PlatformFounderIssuesInboxService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformControlPlaneController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformControlPlaneController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Modules\Organizations\Services\PlatformControlPlaneOverviewService::class),
        $c->get(\Modules\Organizations\Services\FounderAccessPresenter::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformFounderGuideController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformFounderGuideController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class)
    )
);
$container->singleton(
    \Modules\Organizations\Repositories\PlatformTenantAccessReadRepository::class,
    fn ($c) => new \Modules\Organizations\Repositories\PlatformTenantAccessReadRepository($c->get(\Core\App\Database::class))
);
$container->singleton(
    \Modules\Organizations\Services\TenantUserProvisioningService::class,
    fn ($c) => new \Modules\Organizations\Services\TenantUserProvisioningService(
        $c->get(\Core\App\Database::class),
        $c->get(\Core\Audit\AuditService::class),
        $c->get(\Core\Auth\PrincipalAccessService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\FounderAccessManagementService::class,
    fn ($c) => new \Modules\Organizations\Services\FounderAccessManagementService(
        $c->get(\Core\App\Database::class),
        $c->get(\Core\Audit\AuditService::class),
        $c->get(\Modules\Organizations\Services\TenantUserProvisioningService::class),
        $c->get(\Core\Auth\PrincipalAccessService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\FounderImpersonationAuditService::class,
    fn ($c) => new \Modules\Organizations\Services\FounderImpersonationAuditService(
        $c->get(\Core\Audit\AuditService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\FounderSupportEntryService::class,
    fn ($c) => new \Modules\Organizations\Services\FounderSupportEntryService(
        $c->get(\Core\App\Database::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Core\Auth\PrincipalPlaneResolver::class),
        $c->get(\Core\Branch\TenantBranchAccessService::class),
        $c->get(\Modules\Organizations\Services\FounderImpersonationAuditService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformFounderSupportEntryController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformFounderSupportEntryController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Modules\Organizations\Services\FounderSupportEntryService::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class),
        $c->get(\Core\Audit\AuditService::class)
    )
);
$container->singleton(
    \Modules\Auth\Controllers\SupportEntryController::class,
    fn ($c) => new \Modules\Auth\Controllers\SupportEntryController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Modules\Organizations\Services\FounderSupportEntryService::class)
    )
);
// Routed auth/session entrypoints ({@see system/routes/web/register_core_dashboard_auth_public.php}); container-only Dispatcher (A-002 hotfix).
$container->singleton(\Modules\Auth\Controllers\LoginController::class, static fn () => new \Modules\Auth\Controllers\LoginController());
$container->singleton(\Modules\Auth\Controllers\PasswordResetController::class, static fn () => new \Modules\Auth\Controllers\PasswordResetController());
$container->singleton(\Modules\Auth\Controllers\AccountPasswordController::class, static fn () => new \Modules\Auth\Controllers\AccountPasswordController());
$container->singleton(\Modules\Auth\Controllers\TenantEntryController::class, static fn () => new \Modules\Auth\Controllers\TenantEntryController());
$container->singleton(\Modules\Auth\Controllers\BranchContextController::class, static fn () => new \Modules\Auth\Controllers\BranchContextController());
$container->singleton(
    \Modules\Organizations\Controllers\PlatformTenantAccessController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformTenantAccessController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Modules\Organizations\Repositories\PlatformTenantAccessReadRepository::class),
        $c->get(\Core\Auth\UserAccessShapeService::class),
        $c->get(\Modules\Organizations\Services\FounderAccessManagementService::class),
        $c->get(\Modules\Organizations\Services\TenantUserProvisioningService::class),
        $c->get(\Modules\Organizations\Services\FounderAccessPresenter::class),
        $c->get(\Modules\Organizations\Services\FounderAccessImpactExplainer::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\PlatformGlobalBranchManagementService::class,
    fn ($c) => new \Modules\Organizations\Services\PlatformGlobalBranchManagementService(
        $c->get(\Core\App\Database::class),
        $c->get(\Core\Audit\AuditService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformGlobalBranchController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformGlobalBranchController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Modules\Organizations\Services\PlatformGlobalBranchManagementService::class),
        $c->get(\Core\Permissions\PermissionService::class),
        $c->get(\Modules\Organizations\Services\FounderImpactExplainerService::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Repositories\PlatformFounderAuditReadRepository::class,
    fn ($c) => new \Modules\Organizations\Repositories\PlatformFounderAuditReadRepository($c->get(\Core\App\Database::class))
);
$container->singleton(
    \Modules\Organizations\Services\PlatformFounderSecurityService::class,
    fn ($c) => new \Modules\Organizations\Services\PlatformFounderSecurityService(
        $c->get(\Core\App\Database::class),
        $c->get(\Core\App\SettingsService::class),
        $c->get(\Core\Audit\AuditService::class),
        $c->get(\Modules\Organizations\Repositories\PlatformFounderAuditReadRepository::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformFounderSecurityController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformFounderSecurityController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Modules\Organizations\Services\PlatformFounderSecurityService::class),
        $c->get(\Core\Permissions\PermissionService::class),
        $c->get(\Modules\Organizations\Services\FounderAccessPresenter::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class)
    )
);
$container->singleton(
    \Modules\Organizations\Services\FounderGuidedRepairWizardService::class,
    fn ($c) => new \Modules\Organizations\Services\FounderGuidedRepairWizardService(
        $c->get(\Core\Auth\UserAccessShapeService::class),
        $c->get(\Modules\Organizations\Repositories\PlatformTenantAccessReadRepository::class),
        $c->get(\Modules\Organizations\Repositories\PlatformControlPlaneReadRepository::class),
        $c->get(\Modules\Organizations\Services\TenantUserProvisioningService::class),
        $c->get(\Modules\Organizations\Services\FounderAccessImpactExplainer::class)
    )
);
$container->singleton(
    \Modules\Organizations\Controllers\PlatformFounderGuidedRepairController::class,
    fn ($c) => new \Modules\Organizations\Controllers\PlatformFounderGuidedRepairController(
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Core\Auth\SessionAuth::class),
        $c->get(\Modules\Organizations\Services\FounderGuidedRepairWizardService::class),
        $c->get(\Modules\Organizations\Services\FounderAccessManagementService::class),
        $c->get(\Modules\Organizations\Services\OrganizationRegistryReadService::class),
        $c->get(\Modules\Organizations\Services\OrganizationRegistryMutationService::class),
        $c->get(\Modules\Organizations\Services\FounderImpactExplainerService::class),
        $c->get(\Core\Audit\AuditService::class),
        $c->get(\Modules\Organizations\Services\FounderSafeActionGuardrailService::class)
    )
);
