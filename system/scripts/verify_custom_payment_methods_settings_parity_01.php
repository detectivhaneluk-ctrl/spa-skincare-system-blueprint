<?php

declare(strict_types=1);

/**
 * CUSTOM-PAYMENT-METHODS-SETTINGS-PARITY-01 verifier (code contract smoke checks).
 *
 * Usage:
 *   php system/scripts/verify_custom_payment_methods_settings_parity_01.php
 */

$base = dirname(__DIR__);

require $base . '/core/app/autoload.php';

use Modules\Sales\Services\PaymentMethodService;
use Modules\Sales\Support\PaymentMethodFamily;
use Modules\Settings\Support\PaymentSettingsMethodBuckets;

$failed = 0;
$fail = static function (string $msg) use (&$failed): void {
    $failed++;
    fwrite(STDERR, 'FAIL  ' . $msg . "\n");
};
$pass = static function (string $msg): void {
    echo 'PASS  ' . $msg . "\n";
};

$migrationPath = $base . '/data/migrations/097_add_type_label_to_payment_methods.sql';
$migrationSql = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
if ($migrationSql === '') {
    $fail('097 migration file missing');
} elseif (!str_contains($migrationSql, 'ADD COLUMN type_label VARCHAR(50) NULL')) {
    $fail('097 migration must add payment_methods.type_label VARCHAR(50) NULL');
} else {
    $pass('migration adds display-only type_label');
}

$servicePath = $base . '/modules/sales/services/PaymentMethodService.php';
$serviceSrc = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
if ($serviceSrc === '') {
    $fail('PaymentMethodService.php not readable');
} else {
    foreach ([
        'TYPE_LABEL_MAX_LENGTH = 50',
        'normalizeTypeLabel',
        "'type_label'",
        'public function archive(int $id): void',
    ] as $needle) {
        if (!str_contains($serviceSrc, $needle)) {
            $fail('PaymentMethodService missing: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('service has type_label validation + archive operation');
    }
}

$repoPath = $base . '/modules/sales/repositories/PaymentMethodRepository.php';
$repoSrc = is_file($repoPath) ? (string) file_get_contents($repoPath) : '';
if ($repoSrc === '') {
    $fail('PaymentMethodRepository.php not readable');
} else {
    foreach ([
        'type_label',
        'public function archive(int $id): void',
        'SET is_active = 0',
    ] as $needle) {
        if (!str_contains($repoSrc, $needle)) {
            $fail('PaymentMethodRepository missing: ' . $needle);
        }
    }
    if (str_contains($repoSrc, 'DELETE FROM payment_methods')) {
        $fail('PaymentMethodRepository must not hard-delete payment methods');
    } elseif ($failed === 0) {
        $pass('repository supports type_label + soft archive only');
    }
}

$settingsRoutesPath = $base . '/routes/web/register_settings.php';
$routesSrc = is_file($settingsRoutesPath) ? (string) file_get_contents($settingsRoutesPath) : '';
if ($routesSrc === '' || !str_contains($routesSrc, "/settings/payment-methods/{id:\\d+}/archive")) {
    $fail('settings routes must register payment methods archive endpoint');
} elseif ($failed === 0) {
    $pass('settings route includes archive endpoint');
}

$controllerPath = $base . '/modules/settings/controllers/PaymentMethodsController.php';
$controllerSrc = is_file($controllerPath) ? (string) file_get_contents($controllerPath) : '';
if ($controllerSrc === '') {
    $fail('PaymentMethodsController.php not readable');
} else {
    foreach ([
        "trim((string) (\$_POST['type_label'] ?? ''))",
        'public function archive(int $id): void',
        "payment_method_archived",
    ] as $needle) {
        if (!str_contains($controllerSrc, $needle)) {
            $fail('PaymentMethodsController missing: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('controller handles type_label + archived audit action');
    }
}

$familyGift = PaymentMethodFamily::resolve('gift_card', 'Gift card');
$familyCash = PaymentMethodFamily::resolve('cash', 'Cash');
$familyCheck = PaymentMethodFamily::resolve('check', 'Check');
if ($familyGift !== PaymentMethodFamily::GIFT_CARD) {
    $fail('PaymentMethodFamily gift_card semantics changed');
} elseif ($familyCash !== PaymentMethodFamily::CASH || $familyCheck !== PaymentMethodFamily::CHECK) {
    $fail('PaymentMethodFamily check/cash semantics changed');
} else {
    $pass('PaymentMethodFamily semantics unchanged for core families');
}

$bucketed = PaymentSettingsMethodBuckets::bucket([
    ['code' => 'check', 'name' => 'Check'],
    ['code' => 'cash', 'name' => 'Cash'],
    ['code' => 'visa', 'name' => 'Visa'],
]);
if (count($bucketed['checks']) !== 1 || ($bucketed['checks'][0]['code'] ?? '') !== 'check') {
    $fail('PaymentSettingsMethodBuckets checks bucket changed unexpectedly');
} elseif (count($bucketed['cash']) !== 1 || ($bucketed['cash'][0]['code'] ?? '') !== 'cash') {
    $fail('PaymentSettingsMethodBuckets cash bucket changed unexpectedly');
} elseif (count($bucketed['other']) !== 1 || ($bucketed['other'][0]['code'] ?? '') !== 'visa') {
    $fail('PaymentSettingsMethodBuckets other bucket changed unexpectedly');
} else {
    $pass('Payment Settings integration bucketing semantics unchanged');
}

$serviceReflection = new ReflectionClass(PaymentMethodService::class);
if (!$serviceReflection->hasConstant('CODE_GIFT_CARD')) {
    $fail('PaymentMethodService::CODE_GIFT_CARD missing');
} else {
    $pass('payment recording validation constants remain present');
}

exit($failed > 0 ? 1 : 0);
