<?php

declare(strict_types=1);

/**
 * VAT-TYPES-SETTINGS-FOUNDATION-PARITY-01 verifier (contract smoke checks).
 *
 * Usage:
 *   php system/scripts/verify_vat_types_settings_foundation_parity_01.php
 */

$base = dirname(__DIR__);

require $base . '/core/app/autoload.php';

use Modules\Sales\Services\VatRateService;

$failed = 0;
$fail = static function (string $msg) use (&$failed): void {
    $failed++;
    fwrite(STDERR, 'FAIL  ' . $msg . "\n");
};
$pass = static function (string $msg): void {
    echo 'PASS  ' . $msg . "\n";
};

$migrationPath = $base . '/data/migrations/098_add_vat_rates_settings_foundation_fields.sql';
$sql = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
if ($sql === '') {
    $fail('098 migration file missing');
} else {
    foreach ([
        'ADD COLUMN is_flexible TINYINT(1) NOT NULL DEFAULT 0',
        'ADD COLUMN price_includes_tax TINYINT(1) NOT NULL DEFAULT 0',
        'ADD COLUMN applies_to_json JSON NULL',
    ] as $needle) {
        if (!str_contains($sql, $needle)) {
            $fail('098 migration missing: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('migration adds VAT foundation parity columns');
    }
}

$servicePath = $base . '/modules/sales/services/VatRateService.php';
$serviceSrc = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
if ($serviceSrc === '') {
    $fail('VatRateService.php not readable');
} else {
    foreach ([
        "ALLOWED_APPLIES_TO = ['services', 'products', 'memberships', 'add_ons']",
        'normalizeAppliesToTokens',
        "'is_flexible'",
        "'price_includes_tax'",
        "'applies_to_json'",
        'public function archive(int $id): void',
    ] as $needle) {
        if (!str_contains($serviceSrc, $needle)) {
            $fail('VatRateService missing: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('service supports new VAT settings fields + archive');
    }
}

$repoPath = $base . '/modules/sales/repositories/VatRateRepository.php';
$repoSrc = is_file($repoPath) ? (string) file_get_contents($repoPath) : '';
if ($repoSrc === '') {
    $fail('VatRateRepository.php not readable');
} else {
    foreach ([
        'is_flexible',
        'price_includes_tax',
        'applies_to_json',
        'public function archive(int $id): void',
        'SET is_active = 0',
    ] as $needle) {
        if (!str_contains($repoSrc, $needle)) {
            $fail('VatRateRepository missing: ' . $needle);
        }
    }
    if (str_contains($repoSrc, 'DELETE FROM vat_rates')) {
        $fail('VatRateRepository must not hard-delete VAT rates');
    } elseif ($failed === 0) {
        $pass('repository stores new fields and archives safely');
    }
}

$routesPath = $base . '/routes/web/register_settings.php';
$routesSrc = is_file($routesPath) ? (string) file_get_contents($routesPath) : '';
if ($routesSrc === '' || !str_contains($routesSrc, "/settings/vat-rates/{id:\\d+}/archive")) {
    $fail('settings routes must include VAT rate archive endpoint');
} elseif ($failed === 0) {
    $pass('settings routes include VAT rate archive');
}

$controllerPath = $base . '/modules/settings/controllers/VatRatesController.php';
$controllerSrc = is_file($controllerPath) ? (string) file_get_contents($controllerPath) : '';
if ($controllerSrc === '') {
    $fail('VatRatesController.php not readable');
} else {
    foreach ([
        "trim((string) (\$_POST['name'] ?? ''))",
        "(\$_POST['applies_to'] ?? null)",
        "vat_rate_archived",
        "public function archive(int \$id): void",
    ] as $needle) {
        if (!str_contains($controllerSrc, $needle)) {
            $fail('VatRatesController missing: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('controller handles new fields and archive audit');
    }
}

$const = new ReflectionClass(VatRateService::class);
if (!$const->hasConstant('RATE_PERCENT_MAX')) {
    $fail('VatRateService::RATE_PERCENT_MAX missing (tax behavior contract risk)');
} else {
    $pass('existing VAT rate constraints still present');
}

exit($failed > 0 ? 1 : 0);
