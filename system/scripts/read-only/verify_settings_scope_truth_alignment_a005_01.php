<?php

declare(strict_types=1);

/**
 * A-005 read-only: security / notifications / payment policy / hardware receipt gate must not read branch-effective
 * settings when the admin UI for those sections is organization-default only.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_settings_scope_truth_alignment_a005_01.php
 */
$systemRoot = dirname(__DIR__, 2);

function src(string $relativeFromSystem): string
{
    global $systemRoot;

    return (string) file_get_contents($systemRoot . '/' . $relativeFromSystem);
}

$auth = src('core/middleware/AuthMiddleware.php');
$settings = src('core/app/SettingsService.php');
$pay = src('modules/sales/services/PaymentService.php');
$payCtl = src('modules/sales/controllers/PaymentController.php');
$inv = src('modules/sales/services/InvoiceService.php');
$settingsCtl = src('modules/settings/controllers/SettingsController.php');

$checks = [
    'AuthMiddleware: getSecuritySettings(null) for timeout' => str_contains($auth, 'getSecuritySettings(null)[\'inactivity_timeout_minutes\']'),
    'AuthMiddleware: no BranchContext import (org-only security)' => !str_contains($auth, 'use Core\\Branch\\BranchContext'),
    'SettingsService: in-app notification gate uses org notification settings' => str_contains($settings, '// A-005: Notifications admin UI')
        && preg_match('/function\s+shouldEmitInAppNotificationForType[\s\S]*?getNotificationSettings\s*\(\s*null\s*\)/', $settings) === 1,
    'SettingsService: outbound notification gate uses org notification settings' => preg_match(
        '/function\s+shouldEmitOutboundNotificationForEvent[\s\S]*?getNotificationSettings\s*\(\s*null\s*\)/',
        $settings
    ) === 1,
    'PaymentService: payment policy from getPaymentSettings(null)' => str_contains($pay, 'getPaymentSettings(null)'),
    'PaymentService: cash register uses getHardwareSettings(null)' => str_contains($pay, 'getHardwareSettings(null)'),
    'PaymentService: receipt dispatch uses isReceiptPrintingEnabled(null)' => str_contains($pay, 'isReceiptPrintingEnabled(null)'),
    'PaymentController: payment form policy from getPaymentSettings(null)' => substr_count($payCtl, 'getPaymentSettings(null)') >= 4,
    'InvoiceService: gift-card receipt hook uses isReceiptPrintingEnabled(null)' => substr_count($inv, 'isReceiptPrintingEnabled(null)') >= 2,
    'SettingsController: payments policy display uses getPaymentSettings(null)' => str_contains($settingsCtl, '$payment = $settingsService->getPaymentSettings(null)'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
