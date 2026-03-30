<?php

declare(strict_types=1);

/**
 * Static regression: anonymous JSON {@see \Modules\PublicCommerce\Controllers\PublicCommerceController}
 * must not call staff-only queue / staff sync surfaces.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_public_commerce_json_controller_staff_boundary_wave_01.php
 */
$systemRoot = dirname(__DIR__, 2);
$path = $systemRoot . '/modules/public-commerce/controllers/PublicCommerceController.php';
if (!is_readable($path)) {
    fwrite(STDERR, "Missing controller: {$path}\n");
    exit(1);
}
$src = (string) file_get_contents($path);

$banned = [
    'listStaffAwaitingVerificationQueue',
    'staffTrustedFulfillmentSync',
    'PublicCommerceStaffController',
];

$hits = [];
foreach ($banned as $needle) {
    if (str_contains($src, $needle)) {
        $hits[] = $needle;
    }
}

$publicRoutes = $systemRoot . '/routes/web/register_core_dashboard_auth_public.php';
$routeHits = [];
if (is_readable($publicRoutes)) {
    $rsrc = (string) file_get_contents($publicRoutes);
    foreach (['/sales/public-commerce/', 'PublicCommerceStaffController'] as $needle) {
        if (str_contains($rsrc, $needle)) {
            $routeHits[] = $needle;
        }
    }
}

$ok = $hits === [] && $routeHits === [];
if (!$ok) {
    fwrite(STDERR, 'FAIL public_commerce_json_staff_boundary: banned references in PublicCommerceController: '
        . ($hits === [] ? '(none)' : implode(', ', $hits))
        . '; unexpected in public routes file: '
        . ($routeHits === [] ? '(none)' : implode(', ', $routeHits))
        . "\n");
}

exit($ok ? 0 : 1);
