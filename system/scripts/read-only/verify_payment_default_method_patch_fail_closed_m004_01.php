<?php

declare(strict_types=1);

/**
 * M-004: patchPaymentSettings must not skip default_method_code validation when PaymentMethodService is absent.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_payment_default_method_patch_fail_closed_m004_01.php
 */
$systemRoot = dirname(__DIR__, 2);
$settings = (string) file_get_contents($systemRoot . '/core/app/SettingsService.php');

$checks = [
    'patchPaymentSettings requires container PaymentMethodService for default_method_code' => str_contains(
        $settings,
        '!$container->has(PaymentMethodService::class)'
    ),
    'fail-closed message references M-004' => str_contains($settings, 'M-004')
        && str_contains($settings, 'PaymentMethodService must be registered'),
    'validation calls isAllowedForRecordedInvoicePayment' => str_contains(
        $settings,
        'isAllowedForRecordedInvoicePayment($code, $catalogBranch)'
    ),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
