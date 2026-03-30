<?php

declare(strict_types=1);

/**
 * Smoke checks for RECEIPT-INVOICE-SETTINGS-RUNTIME-CONTRACT-FOUNDATION-05 (no DB).
 *
 * Usage:
 *   php system/scripts/verify_receipt_invoice_settings_runtime_contract_foundation_05.php
 */

$base = dirname(__DIR__);

require $base . '/core/app/autoload.php';

use Core\App\SettingsService;
use Modules\Settings\Controllers\SettingsController;

$failed = 0;
$fail = static function (string $msg) use (&$failed): void {
    $failed++;
    fwrite(STDERR, 'FAIL  ' . $msg . "\n");
};
$pass = static function (string $msg): void {
    echo 'PASS  ' . $msg . "\n";
};

$riKeys = SettingsService::RECEIPT_INVOICE_KEYS;
if ($riKeys !== array_values(array_unique($riKeys))) {
    $fail('SettingsService::RECEIPT_INVOICE_KEYS must be unique');
} elseif (count($riKeys) < 10) {
    $fail('RECEIPT_INVOICE_KEYS unexpectedly short');
} else {
    $pass('SettingsService::RECEIPT_INVOICE_KEYS defined (' . count($riKeys) . ' keys)');
}

$reflection = new ReflectionClass(SettingsController::class);
$paymentKeys = $reflection->getReflectionConstant('PAYMENT_WRITE_KEYS')?->getValue();
if (!is_array($paymentKeys)) {
    $fail('PAYMENT_WRITE_KEYS not readable');
} else {
    foreach ($riKeys as $expected) {
        if (!in_array($expected, $paymentKeys, true)) {
            $fail('PAYMENT_WRITE_KEYS missing: ' . $expected);
        }
    }
    if ($failed === 0) {
        $pass('PAYMENT_WRITE_KEYS includes all receipt_invoice.* keys');
    }
}

$allAllowed = $reflection->getReflectionConstant('ALL_ALLOWED_WRITE_KEYS')?->getValue();
if (!is_array($allAllowed)) {
    $fail('ALL_ALLOWED_WRITE_KEYS not readable');
} else {
    foreach ($riKeys as $expected) {
        if (!in_array($expected, $allAllowed, true)) {
            $fail('ALL_ALLOWED_WRITE_KEYS missing: ' . $expected);
        }
    }
    if ($failed === 0) {
        $pass('ALL_ALLOWED_WRITE_KEYS includes receipt_invoice.* keys');
    }
}

$controllerSrc = (string) @file_get_contents($base . '/modules/settings/controllers/SettingsController.php');
foreach (['receiptInvoicePatchFromPost', 'patchReceiptInvoiceSettings', "if (\$activeSection === 'payments')"] as $needle) {
    if (!str_contains($controllerSrc, $needle)) {
        $fail('SettingsController must contain: ' . $needle);
    }
}
if ($failed === 0) {
    $pass('SettingsController receipt save path markers present');
}

$svcPath = $base . '/modules/sales/services/ReceiptInvoicePresentationService.php';
if (!is_file($svcPath)) {
    $fail('ReceiptInvoicePresentationService.php missing');
} else {
    $svc = (string) file_get_contents($svcPath);
    foreach (['buildForInvoiceShow', 'getReceiptInvoiceSettings', 'withProductBarcodes'] as $needle) {
        if (!str_contains($svc, $needle)) {
            $fail('ReceiptInvoicePresentationService must reference: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('ReceiptInvoicePresentationService present');
    }
}

$invCtl = (string) @file_get_contents($base . '/modules/sales/controllers/InvoiceController.php');
foreach (['ReceiptInvoicePresentationService', 'receiptInvoicePresentation', 'buildForInvoiceShow'] as $needle) {
    if (!str_contains($invCtl, $needle)) {
        $fail('InvoiceController must contain: ' . $needle);
    }
}
if ($failed === 0) {
    $pass('InvoiceController wired to presentation service');
}

$show = (string) @file_get_contents($base . '/modules/sales/views/invoices/show.php');
foreach (['$receiptPresentation', 'item_header_label', 'show_item_barcode'] as $needle) {
    if (!str_contains($show, $needle)) {
        $fail('invoices/show.php must contain: ' . $needle);
    }
}
if ($failed === 0) {
    $pass('Invoice show view consumes receipt presentation');
}

$paySvc = (string) @file_get_contents($base . '/modules/sales/services/PaymentService.php');
if (!str_contains($paySvc, 'getEffectiveReceiptFooterText')) {
    $fail('PaymentService must use getEffectiveReceiptFooterText for receipt audit footer');
} elseif ($failed === 0) {
    $pass('PaymentService audit footer uses effective receipt text');
}

exit($failed > 0 ? 1 : 0);
