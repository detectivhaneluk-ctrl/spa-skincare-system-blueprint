<?php

declare(strict_types=1);

/**
 * Smoke checks for PRICE-MODIFICATION-REASONS-DOMAIN-FOUNDATION-07 (no DB).
 *
 * Usage:
 *   php system/scripts/verify_price_modification_reasons_domain_foundation_07.php
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

$migration = $base . '/data/migrations/096_create_price_modification_reasons_table.sql';
if (!is_file($migration)) {
    $fail('migration missing: 096_create_price_modification_reasons_table.sql');
} else {
    $m = (string) file_get_contents($migration);
    foreach (['CREATE TABLE price_modification_reasons', 'live_code', 'uk_price_mod_reasons_live_code'] as $needle) {
        if (!str_contains($m, $needle)) {
            $fail('migration must contain: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('price modification reasons migration present');
    }
}

$repoPath = $base . '/modules/settings/repositories/PriceModificationReasonRepository.php';
$svcPath = $base . '/modules/settings/services/PriceModificationReasonService.php';
$ctlPath = $base . '/modules/settings/controllers/PriceModificationReasonsController.php';
foreach ([$repoPath, $svcPath, $ctlPath] as $p) {
    if (!is_file($p)) {
        $fail('missing file: ' . basename($p));
    }
}

if (is_file($svcPath)) {
    $s = (string) file_get_contents($svcPath);
    foreach (['listActiveForPicker', 'codeExists', 'Reason code must be unique'] as $needle) {
        if (!str_contains($s, $needle)) {
            $fail('service must contain: ' . $needle);
        }
    }
}

$routesPath = $base . '/routes/web/register_settings.php';
$routes = is_file($routesPath) ? (string) file_get_contents($routesPath) : '';
foreach ([
    '/settings/price-modification-reasons',
    'PriceModificationReasonsController',
    "price_modification_reasons.view",
    "price_modification_reasons.manage",
] as $needle) {
    if (!str_contains($routes, $needle)) {
        $fail('settings routes must contain: ' . $needle);
    }
}
if ($failed === 0) {
    $pass('price modification reason routes registered');
}

$partialPath = $base . '/modules/settings/views/partials/payment-settings.php';
$partial = is_file($partialPath) ? (string) file_get_contents($partialPath) : '';
if ($partial === '') {
    $fail('payment-settings partial not readable');
} else {
    if (!str_contains($partial, '/settings/price-modification-reasons')) {
        $fail('payment-settings must link to price modification reasons');
    }
    if (str_contains($partial, '<li>Price modification reasons</li>')) {
        $fail('payment-settings deferred cluster must not list price modification reasons');
    }
}

$shellPath = $base . '/modules/settings/views/partials/shell.php';
$shell = is_file($shellPath) ? (string) file_get_contents($shellPath) : '';
if (!str_contains($shell, "price_modification_reasons")) {
    $fail('settings shell must include price modification reasons section');
}

$seed001 = is_file($base . '/data/seeders/001_seed_roles_permissions.php')
    ? (string) file_get_contents($base . '/data/seeders/001_seed_roles_permissions.php')
    : '';
if (!str_contains($seed001, 'price_modification_reasons.view') || !str_contains($seed001, 'price_modification_reasons.manage')) {
    $fail('001 seeder must include price modification reason permissions');
}

$seed012 = is_file($base . '/data/seeders/012_seed_sync_settings_permissions.php')
    ? (string) file_get_contents($base . '/data/seeders/012_seed_sync_settings_permissions.php')
    : '';
if (!str_contains($seed012, 'price_modification_reasons.view') || !str_contains($seed012, 'price_modification_reasons.manage')) {
    $fail('012 seeder must include price modification reason permissions');
}

if ($failed === 0) {
    $pass('price modification reasons domain wiring checks passed');
}

exit($failed > 0 ? 1 : 0);

