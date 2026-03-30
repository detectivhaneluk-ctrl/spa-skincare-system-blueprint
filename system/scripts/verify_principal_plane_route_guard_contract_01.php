<?php

declare(strict_types=1);

/**
 * Read-only verifier for principal-plane route guard drift.
 *
 * Checks route registrars for:
 * - platform routes without PlatformPrincipalMiddleware
 * - tenant module routes without TenantProtectedRouteMiddleware
 * - mixed contradictory guard registration
 */

$root = dirname(__DIR__);
$targets = [
    $root . '/routes/web/register_core_dashboard_auth_public.php',
    $root . '/routes/web/register_platform_control_plane.php',
    $root . '/routes/web/register_platform_organization_registry.php',
    $root . '/routes/web/register_settings.php',
    $root . '/routes/web/register_branches.php',
    $root . '/routes/web/register_marketing.php',
    $root . '/routes/web/register_payroll.php',
    $root . '/routes/web/register_notifications.php',
    $root . '/routes/web/register_clients.php',
    $root . '/routes/web/register_documents.php',
    $root . '/routes/web/register_staff.php',
    $root . '/routes/web/register_services_resources.php',
    $root . '/routes/web/register_appointments_calendar.php',
    $root . '/routes/web/register_sales_public_commerce_staff.php',
    $root . '/routes/web/register_inventory.php',
    $root . '/routes/web/register_reports.php',
    $root . '/modules/gift-cards/routes/web.php',
    $root . '/modules/packages/routes/web.php',
    $root . '/modules/memberships/routes/web.php',
    $root . '/modules/intake/routes/web.php',
];

$tenantPrefixes = [
    '/dashboard',
    '/appointments',
    '/calendar',
    '/clients',
    '/staff',
    '/inventory',
    '/sales',
    '/memberships',
    '/packages',
    '/gift-cards',
    '/settings',
    '/reports',
    '/branches',
    '/documents',
    '/marketing',
    '/payroll',
    '/notifications',
    '/services-resources',
    '/intake',
];

$neutralPrefixes = [
    '/login',
    '/logout',
    '/password/reset',
    '/tenant-entry',
    '/account/password',
    '/account/branch-context',
    '/api/public/',
    '/public/',
];

$allowlistAuthOnly = [
    'GET /',
];

$errors = [];
$checked = 0;

foreach ($targets as $path) {
    if (!is_file($path)) {
        $errors[] = "missing route file: {$path}";
        continue;
    }
    $content = (string) file_get_contents($path);
    preg_match_all('/\\$router->(get|post)\\(\\s*[\'"]([^\'"]+)[\'"]\\s*,\\s*\\[[^\\]]+\\]\\s*,\\s*\\[([^\\]]*)\\]\\s*\\)/i', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $checked++;
        $method = strtoupper((string) $m[1]);
        $routePath = (string) $m[2];
        $middlewareList = (string) $m[3];
        $key = "{$method} {$routePath}";
        $hasAuth = str_contains($middlewareList, 'AuthMiddleware::class');
        $hasTenant = str_contains($middlewareList, 'TenantProtectedRouteMiddleware::class');
        $hasPlatform = str_contains($middlewareList, 'PlatformPrincipalMiddleware::class');
        $isNeutral = false;
        foreach ($neutralPrefixes as $prefix) {
            if (str_starts_with($routePath, $prefix)) {
                $isNeutral = true;
                break;
            }
        }
        $isTenant = false;
        foreach ($tenantPrefixes as $prefix) {
            if (str_starts_with($routePath, $prefix)) {
                $isTenant = true;
                break;
            }
        }
        $isPlatform = str_starts_with($routePath, '/platform-admin') || str_starts_with($routePath, '/platform/');

        if ($hasTenant && $hasPlatform) {
            $errors[] = "{$key}: contradictory guards (tenant + platform)";
        }

        if ($isPlatform && !$hasPlatform) {
            $errors[] = "{$key}: platform route missing PlatformPrincipalMiddleware";
        }

        if ($isTenant && $hasAuth && !$hasTenant && !in_array($key, $allowlistAuthOnly, true)) {
            $errors[] = "{$key}: tenant route missing TenantProtectedRouteMiddleware";
        }

        if ($isNeutral && $hasTenant) {
            $errors[] = "{$key}: neutral route must not require tenant guard";
        }
        if ($isNeutral && $hasPlatform) {
            $errors[] = "{$key}: neutral route must not require platform guard";
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL  {$error}\n");
    }
    fwrite(STDERR, "\nSummary: 0 passed, " . count($errors) . " failed, {$checked} routes checked.\n");
    exit(1);
}

fwrite(STDOUT, "PASS  principal_plane_route_guard_contract_clean\n");
fwrite(STDOUT, "Summary: 1 passed, 0 failed, {$checked} routes checked.\n");
exit(0);
