<?php

declare(strict_types=1);

/**
 * SALES-PUBLIC-COMMERCE-BOUNDARY-HARDENING-01 — static audit: mixed auth paths for invoice/purchase-related
 * public commerce vs staff bridge; guards and route wiring.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_sales_public_commerce_boundary_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$coreRoutes = (string) file_get_contents($system . '/routes/web/register_core_dashboard_auth_public.php');
$salesRoutes = (string) file_get_contents($system . '/routes/web/register_sales_public_commerce_staff.php');
$pubCtl = (string) file_get_contents($system . '/modules/public-commerce/controllers/PublicCommerceController.php');
$staffCtl = (string) file_get_contents($system . '/modules/public-commerce/controllers/PublicCommerceStaffController.php');
$svc = (string) file_get_contents($system . '/modules/public-commerce/services/PublicCommerceService.php');
$repo = (string) file_get_contents($system . '/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php');
$bootstrap = (string) file_get_contents($system . '/modules/bootstrap/register_sales_public_commerce_memberships_settings.php');

$checks = [];

$checks['Public commerce API routes registered with anonymous middleware ([])'] = str_contains($coreRoutes, '/api/public/commerce/catalog')
    && str_contains($coreRoutes, 'PublicCommerceController::class')
    && str_contains($coreRoutes, "'catalog'], []);")
    && str_contains($coreRoutes, '/api/public/commerce/purchase/status')
    && str_contains($coreRoutes, "'status'], [],")
    && str_contains($coreRoutes, 'csrf_exempt');

$checks['Staff public-commerce routes: Auth + tenant + permission'] = str_contains($salesRoutes, '/sales/public-commerce/awaiting-verification')
    && str_contains($salesRoutes, 'PublicCommerceStaffController::class, \'listAwaitingVerification\'')
    && str_contains($salesRoutes, 'AuthMiddleware::class')
    && str_contains($salesRoutes, 'TenantProtectedRouteMiddleware::class')
    && str_contains($salesRoutes, 'syncFulfillment')
    && str_contains($salesRoutes, "PermissionMiddleware::for('sales.pay')");

$checks['PublicCommerceController does not call staff queue or staffTrustedFulfillmentSync'] = !str_contains($pubCtl, 'listStaffAwaitingVerificationQueue')
    && !str_contains($pubCtl, 'staffTrustedFulfillmentSync');

$checks['PublicCommerceStaffController delegates only to staff-facing service methods'] = str_contains($staffCtl, 'listStaffAwaitingVerificationQueue')
    && str_contains($staffCtl, 'staffTrustedFulfillmentSync');

$checks['listStaffAwaitingVerificationQueue requires authenticated session'] = preg_match('/function listStaffAwaitingVerificationQueue[\s\S]*?if \(\$this->session->id\(\) === null\)[\s\S]*?return \[\]/', $svc) === 1;
$checks['listStaffAwaitingVerificationQueue passes branch + org into repository'] = str_contains($svc, 'listAwaitingVerificationWithInvoices(')
    && str_contains($svc, 'getCurrentBranchId()')
    && str_contains($svc, 'getCurrentOrganizationId()');

$checks['listAwaitingVerificationWithInvoices fail-closed (branch OR org EXISTS)'] = preg_match(
    '/function listAwaitingVerificationWithInvoices\(\?int \$branchId, \?int \$organizationId/',
    $repo
) === 1 && str_contains($repo, 'AND EXISTS (')
    && str_contains($repo, 'FROM branches b')
    && str_contains($repo, 'else {')
    && str_contains($repo, 'return [];');

$checks['staffTrustedFulfillmentSync: unauthenticated rejected + branch assert after invoice find'] = preg_match(
    '/function staffTrustedFulfillmentSync[\s\S]*?\$actorId = \$this->session->id\(\)[\s\S]*?unauthenticated[\s\S]*?invoiceRepo->find\(\$invoiceId\)[\s\S]*?branchContext->assertBranchMatchOrGlobalEntity/',
    $svc
) === 1;

$checks['Bootstrap: PublicCommerceService receives OrganizationContext'] = str_contains($bootstrap, 'PublicCommerceService::class')
    && str_contains($bootstrap, 'OrganizationContext::class');

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'All public-commerce boundary static checks passed.' . PHP_EOL;
exit(0);
