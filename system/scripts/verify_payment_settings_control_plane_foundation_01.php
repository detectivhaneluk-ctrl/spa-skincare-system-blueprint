<?php

declare(strict_types=1);

/**
 * Smoke checks for PAYMENT-SETTINGS-BOOKER-STRUCTURE-AND-WRITE-CONTRACT-FOUNDATION-01 (no DB).
 *
 * Usage:
 *   php system/scripts/verify_payment_settings_control_plane_foundation_01.php
 */

$base = dirname(__DIR__);

require $base . '/core/app/autoload.php';

use Core\App\SettingsService;
use Modules\Settings\Controllers\SettingsController;
use Modules\Settings\Support\PaymentSettingsMethodBuckets;
use Modules\Sales\Services\PaymentMethodService;

$failed = 0;
$fail = static function (string $msg) use (&$failed): void {
    $failed++;
    fwrite(STDERR, 'FAIL  ' . $msg . "\n");
};
$pass = static function (string $msg): void {
    echo 'PASS  ' . $msg . "\n";
};

$reflection = new ReflectionClass(SettingsController::class);
$paymentKeys = $reflection->getReflectionConstant('PAYMENT_WRITE_KEYS')?->getValue();
if (!is_array($paymentKeys)) {
    $fail('PAYMENT_WRITE_KEYS not readable');
} else {
    foreach ([
        'payments.default_method_code',
        'payments.allow_partial_payments',
        'payments.allow_overpayments',
        'payments.receipt_notes',
        'public_commerce.allow_gift_cards',
        'public_commerce.gift_card_min_amount',
        'public_commerce.gift_card_max_amount',
    ] as $expected) {
        if (!in_array($expected, $paymentKeys, true)) {
            $fail('PAYMENT_WRITE_KEYS missing: ' . $expected);
        }
    }
    if ($paymentKeys !== array_values(array_unique($paymentKeys))) {
        $fail('PAYMENT_WRITE_KEYS must be unique');
    }
    foreach (SettingsService::RECEIPT_INVOICE_KEYS as $rk) {
        if (!in_array($rk, $paymentKeys, true)) {
            $fail('PAYMENT_WRITE_KEYS missing receipt key: ' . $rk);
        }
    }
    if ($failed === 0) {
        $pass('PAYMENT_WRITE_KEYS carries payments.* + gift-card public_commerce.* + receipt_invoice.* (' . count($paymentKeys) . ' keys)');
    }
}

$controllerPath = $base . '/modules/settings/controllers/SettingsController.php';
$controller = is_file($controllerPath) ? (string) file_get_contents($controllerPath) : '';
if ($controller === '') {
    $fail('SettingsController.php not readable');
} else {
    foreach ([
        "if (\$activeSection === 'payments')",
        'patchPublicCommerceSettings($giftPatch',
        'patchPaymentSettings($paymentPatch',
        'receiptInvoicePatchFromPost',
        'PAYMENTS_BRANCH_PARAM',
        'PAYMENTS_CONTEXT_BRANCH_POST',
        'payments_branch_id',
    ] as $needle) {
        if (!str_contains($controller, $needle)) {
            $fail('SettingsController must contain: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('SettingsController payment save surface markers present');
    }
}

$partial = $base . '/modules/settings/views/partials/payment-settings.php';
if (!is_file($partial)) {
    $fail('payment-settings partial missing');
} elseif ($failed === 0) {
    $pass('payment-settings partial exists');
}

$b = PaymentSettingsMethodBuckets::bucket([
    ['code' => 'check', 'name' => 'Check'],
    ['code' => 'cash', 'name' => 'Cash'],
    ['code' => 'visa', 'name' => 'Visa'],
]);
if (count($b['checks']) !== 1 || ($b['checks'][0]['code'] ?? '') !== 'check') {
    $fail('bucket checks expected one check row');
} elseif (count($b['cash']) !== 1 || ($b['cash'][0]['code'] ?? '') !== 'cash') {
    $fail('bucket cash expected one cash row');
} elseif (count($b['other']) !== 1 || ($b['other'][0]['code'] ?? '') !== 'visa') {
    $fail('bucket other expected visa');
} elseif ($failed === 0) {
    $pass('PaymentSettingsMethodBuckets smoke classification OK');
}

$pm = new ReflectionClass(PaymentMethodService::class);
if (!$pm->hasConstant('CODE_GIFT_CARD')) {
    $fail('PaymentMethodService::CODE_GIFT_CARD missing');
} elseif ($failed === 0) {
    $pass('PaymentMethodService gift code constant present');
}

exit($failed > 0 ? 1 : 0);
