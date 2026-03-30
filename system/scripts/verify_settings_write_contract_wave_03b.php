<?php

declare(strict_types=1);

/**
 * Smoke checks for SETTINGS-APPOINTMENT-WRITE-CONTRACT-HARDENING-WAVE-03B (no DB).
 *
 * Usage:
 *   php system/scripts/verify_settings_write_contract_wave_03b.php
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

foreach (SettingsService::APPOINTMENT_SETTINGS_FORM_KEYS as $k) {
    if (!in_array($k, SettingsService::APPOINTMENT_KEYS, true)) {
        $fail('APPOINTMENT_SETTINGS_FORM_KEYS must be subset of APPOINTMENT_KEYS: ' . $k);
    }
}
if (in_array('appointments.prebook_threshold_hours', SettingsService::APPOINTMENT_SETTINGS_FORM_KEYS, true)) {
    $fail('APPOINTMENT_SETTINGS_FORM_KEYS must not include legacy prebook_threshold_hours');
}
if ($failed === 0) {
    $pass('appointment form keys are canonical subset (' . count(SettingsService::APPOINTMENT_SETTINGS_FORM_KEYS) . ' keys)');
}

if (count(SettingsService::CANCELLATION_KEYS) !== count(array_unique(SettingsService::CANCELLATION_KEYS))) {
    $fail('CANCELLATION_KEYS must be unique');
} elseif ($failed === 0) {
    $pass('cancellation keys unique (' . count(SettingsService::CANCELLATION_KEYS) . ' keys)');
}

if (SettingsService::WAITLIST_KEYS !== array_values(array_unique(SettingsService::WAITLIST_KEYS))) {
    $fail('WAITLIST_KEYS must be unique');
} elseif ($failed === 0) {
    $pass('waitlist keys OK (' . count(SettingsService::WAITLIST_KEYS) . ' keys)');
}

if (SettingsService::SECURITY_KEYS !== array_values(array_unique(SettingsService::SECURITY_KEYS))) {
    $fail('SECURITY_KEYS must be unique');
} elseif ($failed === 0) {
    $pass('security keys OK (' . count(SettingsService::SECURITY_KEYS) . ' keys)');
}

$controllerPath = $base . '/modules/settings/controllers/SettingsController.php';
$controller = is_file($controllerPath) ? (string) file_get_contents($controllerPath) : '';
if ($controller === '') {
    $fail('SettingsController.php not readable');
} else {
    foreach ([
        'SettingsService::CANCELLATION_KEYS',
        'SettingsService::APPOINTMENT_SETTINGS_FORM_KEYS',
        'SettingsService::WAITLIST_KEYS',
        'SettingsService::SECURITY_KEYS',
        "\$activeSection === 'cancellation'",
        "\$activeSection === 'appointments'",
        "\$activeSection === 'waitlist'",
        "\$activeSection === 'security'",
    ] as $needle) {
        if (!str_contains($controller, $needle)) {
            $fail('SettingsController must contain: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('SettingsController wave-03b markers present');
    }
}

$service = is_file($base . '/core/app/SettingsService.php') ? (string) file_get_contents($base . '/core/app/SettingsService.php') : '';
if ($service === '') {
    $fail('SettingsService.php not readable');
} else {
    foreach (['patchCancellationSettings', 'patchAppointmentSettings', 'patchWaitlistSettings', 'patchSecuritySettings'] as $m) {
        $pos = strpos($service, "function {$m}");
        if ($pos === false) {
            $fail("SettingsService missing {$m}");
            continue;
        }
        $head = substr($service, $pos, 800);
        if (!str_contains($head, 'onlyPatchKeys')) {
            $fail("{$m} should call onlyPatchKeys near the top");
        }
    }
    if ($failed === 0) {
        $pass('SettingsService patch methods include onlyPatchKeys');
    }
}

exit($failed > 0 ? 1 : 0);
