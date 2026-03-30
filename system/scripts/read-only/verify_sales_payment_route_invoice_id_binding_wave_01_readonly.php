<?php

declare(strict_types=1);

/**
 * SALES-BACKEND-TRUTH-MAP-WAVE-01 — read-only: payment create payload must use route invoice id,
 * not $_POST['invoice_id'], so branch-validated invoice and persisted payment target stay aligned.
 *
 * Run from repo root:
 *   php system/scripts/read-only/verify_sales_payment_route_invoice_id_binding_wave_01_readonly.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/sales/controllers/PaymentController.php';
$src = is_readable($path) ? (string) file_get_contents($path) : '';

if ($src === '') {
    fwrite(STDERR, "FAIL: cannot read PaymentController.php\n");
    exit(1);
}

$ok = str_contains($src, "'invoice_id' => \$invoiceId")
    && !str_contains($src, "'invoice_id' => (int) (\$_POST['invoice_id']");

if (!$ok) {
    fwrite(STDERR, "FAIL: PaymentController must bind invoice_id to route \$invoiceId and must not read invoice_id from POST.\n");
    exit(1);
}

// parseInput must still receive $invoiceId (signature contract).
if (!preg_match('/function\s+parseInput\s*\(\s*int\s+\$invoiceId\s*,\s*array\s+\$invoice\s*\)/', $src)) {
    fwrite(STDERR, "FAIL: parseInput(int \$invoiceId, array \$invoice) signature expected.\n");
    exit(1);
}

echo "PASS verify_sales_payment_route_invoice_id_binding_wave_01_readonly\n";
exit(0);
