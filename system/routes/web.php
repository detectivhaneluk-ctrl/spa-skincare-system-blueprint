<?php

declare(strict_types=1);

/**
 * HTTP route orchestrator: loads domain registrar files in strict registration order,
 * then existing module route files (unchanged relative order vs MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-02).
 *
 * @see system/docs/ROUTE-REGISTRATION-TOPOLOGY-TRUTH-OPS.md
 */

$routeRegistrarDir = __DIR__ . '/web/';
$routeRegistrars = [
    'register_core_dashboard_auth_public.php',
    'register_settings.php',
    'register_branches.php',
    'register_platform_organization_registry.php',
    'register_platform_control_plane.php',
    'register_marketing.php',
    'register_payroll.php',
    'register_notifications.php',
    'register_clients.php',
    'register_documents.php',
    'register_media.php',
    'register_staff.php',
    'register_services_resources.php',
    'register_appointments_calendar.php',
    'register_sales_public_commerce_staff.php',
    'register_inventory.php',
    'register_reports.php',
];

foreach ($routeRegistrars as $registrar) {
    require $routeRegistrarDir . $registrar;
}

require base_path('modules/intake/routes/web.php');
require base_path('modules/gift-cards/routes/web.php');
require base_path('modules/packages/routes/web.php');
require base_path('modules/memberships/routes/web.php');
