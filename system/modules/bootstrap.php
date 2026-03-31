<?php

declare(strict_types=1);

/**
 * Module service bindings. Loaded after core bootstrap.
 *
 * Ends with `OrganizationContextResolver` then `StaffMultiOrgOrganizationResolutionGate`
 * (gate depends on resolver; both require module-registered org membership services).
 *
 * Thin orchestrator: ordered registrar fragments under modules/bootstrap/ preserve the
 * mechanical singleton sequence from MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-01.
 *
 * @see system/docs/BOOTSTRAP-REGISTRATION-TOPOLOGY-TRUTH-OPS.md
 * @see system/docs/MAINTAINER-RUNTIME-TRUTH.md (bootstrap chain)
 */

$container = \Core\App\Application::container();

$registrarDir = SYSTEM_PATH . '/modules/bootstrap/';
$registrars = [
    'register_storage.php',
    'register_observability.php',
    'register_clients.php',
    'register_staff.php',
    'register_services_resources.php',
    'register_appointments_documents_notifications.php',
    'register_appointments_online_contracts.php',
    'register_gift_cards.php',
    'register_packages.php',
    'register_memberships_repositories.php',
    'register_inventory.php',
    'register_sales_public_commerce_memberships_settings.php',
    'register_branches.php',
    'register_organizations.php',
    'register_reports.php',
    'register_dashboard.php',
    'register_intake.php',
    'register_media.php',
    'register_marketing.php',
    'register_payroll.php',
    'register_async_queue.php',
];

foreach ($registrars as $registrar) {
    require $registrarDir . $registrar;
}

$container->singleton(
    \Core\Organization\OrganizationContextResolver::class,
    fn ($c) => new \Core\Organization\OrganizationContextResolver(
        $c->get(\Core\App\Database::class),
        $c->get(\Core\Auth\AuthService::class),
        $c->get(\Modules\Organizations\Services\UserOrganizationMembershipReadService::class),
        $c->get(\Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::class)
    )
);

// Depends on OrganizationContextResolver — must stay immediately after resolver registration (A-001).
$container->singleton(\Core\Organization\StaffMultiOrgOrganizationResolutionGate::class, fn ($c) => new \Core\Organization\StaffMultiOrgOrganizationResolutionGate(
    $c->get(\Core\Organization\OrganizationContext::class),
    $c->get(\Core\Organization\OrganizationContextResolver::class)
));
