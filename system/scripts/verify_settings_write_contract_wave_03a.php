<?php

declare(strict_types=1);

/**
 * Smoke checks for SETTINGS-WRITE-CONTRACT-HARDENING-WAVE-03A (no DB, no HTTP).
 *
 * Usage:
 *   php system/scripts/verify_settings_write_contract_wave_03a.php
 */

$base = dirname(__DIR__);

require $base . '/core/app/autoload.php';

use Core\App\SettingsService;

$failed = 0;

$fail = static function (string $msg) use (&$failed): void {
    $failed++;
    fwrite(STDERR, 'FAIL  ' . $msg . "\n");
};

$pass = static function (string $msg): void {
    echo 'PASS  ' . $msg . "\n";
};

$merged = [
    ...SettingsService::ONLINE_BOOKING_KEYS,
    ...SettingsService::INTAKE_KEYS,
    ...SettingsService::PUBLIC_COMMERCE_KEYS,
];
if (count($merged) !== count(array_unique($merged))) {
    $fail('public_channels merged keys must be unique');
} else {
    $pass('public_channels merge is unique (' . count($merged) . ' keys)');
}

$controllerPath = $base . '/modules/settings/controllers/SettingsController.php';
$controller = is_file($controllerPath) ? (string) file_get_contents($controllerPath) : '';
if ($controller === '') {
    $fail('SettingsController.php not readable');
} else {
    foreach ([
        'PUBLIC_CHANNELS_WRITE_KEYS',
        'SettingsService::ONLINE_BOOKING_KEYS',
        'SettingsService::INTAKE_KEYS',
        'SettingsService::PUBLIC_COMMERCE_KEYS',
        'SettingsService::NOTIFICATIONS_KEYS',
        'SettingsService::MEMBERSHIPS_KEYS',
        'collectStrippedRawSettingsKeys',
    ] as $needle) {
        if (!str_contains($controller, $needle)) {
            $fail('SettingsController must contain: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('SettingsController wave-03a markers present');
    }
}

$servicePath = $base . '/core/app/SettingsService.php';
$service = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
if ($service === '' || !str_contains($service, 'onlyPatchKeys')) {
    $fail('SettingsService must define onlyPatchKeys');
} elseif ($failed === 0) {
    $pass('SettingsService patch hardening marker present');
}

exit($failed > 0 ? 1 : 0);
