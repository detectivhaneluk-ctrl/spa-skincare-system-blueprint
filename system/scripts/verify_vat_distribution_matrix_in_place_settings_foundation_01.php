<?php

declare(strict_types=1);

/**
 * VAT-DISTRIBUTION-MATRIX-IN-PLACE-SETTINGS-FOUNDATION-01 verifier.
 *
 * Usage:
 *   php system/scripts/verify_vat_distribution_matrix_in_place_settings_foundation_01.php
 */

$base = dirname(__DIR__);
require $base . '/core/app/autoload.php';

$failed = 0;
$fail = static function (string $msg) use (&$failed): void {
    $failed++;
    fwrite(STDERR, 'FAIL  ' . $msg . "\n");
};
$pass = static function (string $msg): void {
    echo 'PASS  ' . $msg . "\n";
};

$routes = (string) @file_get_contents($base . '/routes/web/register_settings.php');
foreach ([
    "/settings/vat-distribution-guide', [\\Modules\\Settings\\Controllers\\VatDistributionController::class, 'index']",
    "/settings/vat-distribution-guide', [\\Modules\\Settings\\Controllers\\VatDistributionController::class, 'store']",
    "PermissionMiddleware::for('vat_rates.view')",
    "PermissionMiddleware::for('vat_rates.manage')",
] as $needle) {
    if (!str_contains($routes, $needle)) {
        $fail('register_settings missing: ' . $needle);
    }
}
if (!str_contains($routes, '/reports/vat-distribution')) {
    $pass('settings routes do not touch reports endpoint');
}

$reportRoutes = (string) @file_get_contents($base . '/routes/web/register_reports.php');
if (!str_contains($reportRoutes, "/reports/vat-distribution")) {
    $fail('/reports/vat-distribution route missing');
} else {
    $pass('/reports/vat-distribution route still present');
}

$controller = (string) @file_get_contents($base . '/modules/settings/controllers/VatDistributionController.php');
foreach ([
    "MATRIX_DOMAINS = ['products', 'services', 'memberships']",
    'bulkUpdateGlobalApplicabilityMatrix',
    'vat_distribution_matrix_updated',
] as $needle) {
    if (!str_contains($controller, $needle)) {
        $fail('VatDistributionController missing: ' . $needle);
    }
}

$view = (string) @file_get_contents($base . '/modules/settings/views/vat-distribution-guide.php');
foreach ([
    'action="/settings/vat-distribution-guide"',
    'value="products"',
    'value="services"',
    'value="memberships"',
    '/reports/vat-distribution',
] as $needle) {
    if (!str_contains($view, $needle)) {
        $fail('vat-distribution-guide view missing: ' . $needle);
    }
}

$service = (string) @file_get_contents($base . '/modules/sales/services/VatRateService.php');
foreach ([
    'bulkUpdateGlobalApplicabilityMatrix',
    'sort($tokens)',
] as $needle) {
    if (!str_contains($service, $needle)) {
        $fail('VatRateService missing: ' . $needle);
    }
}

$repo = (string) @file_get_contents($base . '/modules/sales/repositories/VatRateRepository.php');
if (!str_contains($repo, 'bulkUpdateGlobalActiveApplicability')) {
    $fail('VatRateRepository bulk update method missing');
}

exit($failed > 0 ? 1 : 0);
