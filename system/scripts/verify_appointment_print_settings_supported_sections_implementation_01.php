<?php

declare(strict_types=1);

/**
 * APPOINTMENT-PRINT-SETTINGS-SUPPORTED-SECTIONS-IMPLEMENTATION-01 — static proof (no DB).
 * Includes APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01 fourth print toggle.
 *
 * From system/:
 *   php scripts/verify_appointment_print_settings_supported_sections_implementation_01.php
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function vPsPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vPsFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

$keys = [
    'appointments.print_show_staff_appointment_list',
    'appointments.print_show_client_service_history',
    'appointments.print_show_package_detail',
    'appointments.print_show_client_product_purchase_history',
];

$ss = (string) file_get_contents($root . '/core/app/SettingsService.php');
foreach ($keys as $k) {
    if (!str_contains($ss, "'" . $k . "'")) {
        vPsFail('settings_service_key_' . $k, 'missing in SettingsService');
    } else {
        vPsPass('settings_service_has_' . str_replace(['appointments.', '.'], ['', '_'], $k));
    }
}

$expectTrueDefault = [
    'appointments.print_show_staff_appointment_list',
    'appointments.print_show_client_service_history',
    'appointments.print_show_package_detail',
];
foreach ($expectTrueDefault as $k) {
    if (!str_contains($ss, "getBool('" . $k . "', true,")) {
        vPsFail('settings_getAppointment_' . $k, 'getBool true default missing');
    } else {
        vPsPass('settings_getBool_true_' . str_replace(['appointments.', '.'], ['', '_'], $k));
    }
}
if (!str_contains($ss, "getBool('appointments.print_show_client_product_purchase_history', false,")) {
    vPsFail('settings_getAppointment_product_history', 'getBool false default for product purchase');
} else {
    vPsPass('settings_getBool_false_product_purchase_history');
}

if (!str_contains($ss, 'print_show_client_product_purchase_history') || !str_contains($ss, 'patchAppointmentSettings')) {
    vPsFail('settings_patch', 'patch block');
} else {
    vPsPass('settings_patchAppointmentSettings_print_keys');
}

$sc = (string) file_get_contents($root . '/modules/settings/controllers/SettingsController.php');
foreach ($keys as $k) {
    if (!str_contains($sc, "'" . $k . "'")) {
        vPsFail('settings_controller_' . $k, 'missing allowlist / mapping');
    } else {
        vPsPass('settings_controller_has_' . str_replace(['appointments.', '.'], ['', '_'], $k));
    }
}

$idx = (string) file_get_contents($root . '/modules/settings/views/index.php');
foreach ($keys as $k) {
    if (!str_contains($idx, $k)) {
        vPsFail('settings_ui_' . $k, 'checkbox name attribute');
    } else {
        vPsPass('settings_ui_has_' . str_replace(['appointments.', '.'], ['', '_'], $k));
    }
}

$svc = (string) file_get_contents($root . '/modules/appointments/services/AppointmentPrintSummaryService.php');
if (!str_contains($svc, 'SettingsService') || !str_contains($svc, 'print_show_staff_appointment_list')) {
    vPsFail('print_service', 'Expected SettingsService + print_show keys');
} else {
    vPsPass('print_service_reads_settings');
}
if (!str_contains($svc, 'print_show_client_product_purchase_history')) {
    vPsFail('print_service_product_setting', 'product purchase print gate');
} else {
    vPsPass('print_service_product_setting');
}
if (!str_contains($svc, 'section_visibility')) {
    vPsFail('print_service_visibility', 'section_visibility missing');
} else {
    vPsPass('print_service_section_visibility');
}

$view = (string) file_get_contents($root . '/modules/appointments/views/print.php');
if (!str_contains($view, 'section_visibility') || !str_contains($view, 'showStaffSection')) {
    vPsFail('print_view', 'Expected section_visibility wiring');
} else {
    vPsPass('print_view_respects_section_visibility');
}
if (!str_contains($view, 'showProductPurchaseSection') || !str_contains($view, 'Client product purchase history')) {
    vPsFail('print_view_product_section', 'Product purchase section + visibility flag');
} else {
    vPsPass('print_view_product_purchase_section');
}

echo "\nDone. Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
