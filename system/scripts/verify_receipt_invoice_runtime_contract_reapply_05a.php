<?php

declare(strict_types=1);

/**
 * Smoke checks for RECEIPT-INVOICE-RUNTIME-CONTRACT-REAPPLY-05A (no DB).
 *
 * Usage:
 *   php system/scripts/verify_receipt_invoice_runtime_contract_reapply_05a.php
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

// 1) SettingsService domain + keys.
$svcPath = $base . '/core/app/SettingsService.php';
$svc = is_file($svcPath) ? (string) file_get_contents($svcPath) : '';
if ($svc === '') {
    $fail('SettingsService.php not readable');
} else {
    foreach ([
        'getReceiptInvoiceSettings',
        'patchReceiptInvoiceSettings',
        'receipt_invoice.show_establishment_name',
        'receipt_invoice.show_client_block',
        'receipt_invoice.item_header_label',
        'receipt_invoice.footer_bank_details',
        'receipt_invoice.footer_text',
        'receipt_invoice.receipt_message',
        'receipt_invoice.invoice_message',
    ] as $needle) {
        if (!str_contains($svc, $needle)) {
            $fail('SettingsService missing needle: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('SettingsService receipt_invoice domain + keys present');
    }
}

// 2) Payment settings UI has receipt edit mode and no payments.receipt_notes in cards block.
$partialPath = $base . '/modules/settings/views/partials/payment-settings.php';
$partial = is_file($partialPath) ? (string) file_get_contents($partialPath) : '';
if ($partial === '') {
    $fail('payment-settings.php not readable');
} else {
    if (!str_contains($partial, "['cards', 'gift', 'receipt']")) {
        $fail('payment-settings allowed edits must include receipt');
    }
    if (str_contains($partial, 'name="settings[payments.receipt_notes]"')) {
        $fail('payment-settings must not post payments.receipt_notes (legacy fallback only)');
    }
    if (!str_contains($partial, "payment_edit')")) {
        $fail('payment-settings must use payment_edit mode');
    }
    if ($failed === 0) {
        $pass('payment-settings receipt edit mode present; legacy receipt_notes not in cards form');
    }
}

// 3) Resolver service exists.
$resolverPath = $base . '/modules/sales/services/ReceiptInvoicePresentationService.php';
if (!is_file($resolverPath)) {
    $fail('ReceiptInvoicePresentationService.php missing');
} else {
    $resolver = (string) file_get_contents($resolverPath);
    foreach (['buildForInvoiceShow', 'presentation', 'has_any_barcode'] as $needle) {
        if (!str_contains($resolver, $needle)) {
            $fail('ReceiptInvoicePresentationService missing needle: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('ReceiptInvoicePresentationService present');
    }
}

// 4) InvoiceController uses resolver.
$invCtlPath = $base . '/modules/sales/controllers/InvoiceController.php';
$invCtl = is_file($invCtlPath) ? (string) file_get_contents($invCtlPath) : '';
if ($invCtl === '') {
    $fail('InvoiceController.php not readable');
} else {
    foreach (['ReceiptInvoicePresentationService', 'buildForInvoiceShow', '$receiptPresentation'] as $needle) {
        if (!str_contains($invCtl, $needle)) {
            $fail('InvoiceController missing needle: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('InvoiceController wired to resolver');
    }
}

// 5) Invoice show view consumes resolved config.
$invViewPath = $base . '/modules/sales/views/invoices/show.php';
$invView = is_file($invViewPath) ? (string) file_get_contents($invViewPath) : '';
if ($invView === '') {
    $fail('invoices/show.php not readable');
} else {
    foreach (['$p = $receiptPresentation[\'presentation\']', 'item_header_label', 'show_client_block'] as $needle) {
        if (!str_contains($invView, $needle)) {
            $fail('invoices/show.php missing needle: ' . $needle);
        }
    }
    if ($failed === 0) {
        $pass('Invoice show view consumes presentation config');
    }
}

// 6) Static key list matches minimum required set.
$requiredKeys = [
    'receipt_invoice.show_establishment_name',
    'receipt_invoice.show_establishment_address',
    'receipt_invoice.show_establishment_phone',
    'receipt_invoice.show_establishment_email',
    'receipt_invoice.show_client_block',
    'receipt_invoice.show_client_phone',
    'receipt_invoice.show_client_address',
    'receipt_invoice.show_recorded_by',
    'receipt_invoice.show_item_barcode',
    'receipt_invoice.item_header_label',
    'receipt_invoice.item_sort_mode',
    'receipt_invoice.footer_bank_details',
    'receipt_invoice.footer_text',
    'receipt_invoice.receipt_message',
    'receipt_invoice.invoice_message',
];
foreach ($requiredKeys as $k) {
    if (!in_array($k, SettingsService::RECEIPT_INVOICE_KEYS, true)) {
        $fail('SettingsService::RECEIPT_INVOICE_KEYS missing: ' . $k);
    }
}
if ($failed === 0) {
    $pass('SettingsService::RECEIPT_INVOICE_KEYS contains required set');
}

exit($failed > 0 ? 1 : 0);

