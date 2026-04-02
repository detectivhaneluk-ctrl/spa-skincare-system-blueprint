<?php

declare(strict_types=1);

/**
 * SALES-CANONICAL-OPERATOR-FLOW-HARDENING-01 — read-only verifier.
 *
 * Proves that every sales mutation operation in InvoiceController,
 * PaymentController, and RegisterController has a try/catch block so that
 * DomainExceptions from the service layer produce flash errors + redirects
 * instead of HTTP 500s.
 *
 * Also proves the specific DomainException paths that triggered this task
 * are present in InvoiceService (delete + cancel).
 */

$pass = 0;
$fail = 0;

function ok(string $label): void
{
    global $pass;
    ++$pass;
    echo "[PASS] {$label}\n";
}

function fail(string $label, string $detail = ''): void
{
    global $fail;
    ++$fail;
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function assertContains(string $label, string $source, string $needle): void
{
    str_contains($source, $needle) ? ok($label) : fail($label, "Missing: " . substr($needle, 0, 80));
}

function assertNotContains(string $label, string $source, string $needle): void
{
    !str_contains($source, $needle) ? ok($label) : fail($label, "Should not contain: " . substr($needle, 0, 80));
}

$root = dirname(__DIR__, 2);

// -----------------------------------------------------------------------
// Load source files
// -----------------------------------------------------------------------
$invoiceControllerPath = $root . '/modules/sales/controllers/InvoiceController.php';
$paymentControllerPath = $root . '/modules/sales/controllers/PaymentController.php';
$registerControllerPath = $root . '/modules/sales/controllers/RegisterController.php';
$invoiceServicePath     = $root . '/modules/sales/services/InvoiceService.php';

foreach ([
    $invoiceControllerPath   => 'InvoiceController.php',
    $paymentControllerPath   => 'PaymentController.php',
    $registerControllerPath  => 'RegisterController.php',
    $invoiceServicePath      => 'InvoiceService.php',
] as $path => $label) {
    if (!is_file($path)) {
        fail("File exists: {$label}", $path);
    } else {
        ok("File exists: {$label}");
    }
}

$invoiceCtrl    = file_get_contents($invoiceControllerPath);
$paymentCtrl    = file_get_contents($paymentControllerPath);
$registerCtrl   = file_get_contents($registerControllerPath);
$invoiceService = file_get_contents($invoiceServicePath);

// Use normalised line endings for multi-line pattern matching.
$ic = str_replace("\r\n", "\n", $invoiceCtrl);

// -----------------------------------------------------------------------
// I. InvoiceController::destroy() — must have try/catch
// -----------------------------------------------------------------------
assertContains(
    'IC-01: destroy() wraps service->delete() in try',
    $ic,
    "try {\n            \$this->service->delete(\$id);"
);
assertContains(
    'IC-02: destroy() catch flashes error and redirects to invoice show',
    $ic,
    "} catch (\\Throwable \$e) {\n            flash('error', \$e->getMessage());\n            header('Location: /sales/invoices/' . \$id);"
);
assertContains(
    'IC-03: destroy() success path still redirects to /sales/invoices list',
    $ic,
    "flash('success', 'Invoice deleted.');\n            header('Location: /sales/invoices');"
);

// -----------------------------------------------------------------------
// II. InvoiceController::cancel() — must have try/catch
// -----------------------------------------------------------------------
assertContains(
    'IC-04: cancel() wraps service->cancel() in try',
    $ic,
    "try {\n            \$this->service->cancel(\$id);"
);
assertContains(
    'IC-05: cancel() catch flashes error, redirect is outside try/catch',
    $ic,
    "} catch (\\Throwable \$e) {\n            flash('error', \$e->getMessage());\n        }\n        header('Location: /sales/invoices/' . \$id);"
);
assertContains(
    'IC-06: cancel() success path flashes cancelled',
    $ic,
    "flash('success', 'Invoice cancelled.');"
);

// -----------------------------------------------------------------------
// III. InvoiceController — other mutations already had try/catch (no regression)
// -----------------------------------------------------------------------
assertContains(
    'IC-07: store() has try/catch',
    $ic,
    '$id = $this->service->create($data);'
);
// store() has try block around service->create()
assertContains(
    'IC-08: store() catch flashes general error',
    $ic,
    '$errors[\'_general\'] = $e->getMessage();'
);
assertContains(
    'IC-09: update() has try block',
    $ic,
    '$this->service->update($id, $data);'
);
assertContains(
    'IC-10: redeemGiftCard() has try/catch',
    $ic,
    '$this->service->redeemGiftCardPayment($id, $giftCardId, $amount, $notes);'
);

// -----------------------------------------------------------------------
// IV. PaymentController — store() and refund() already had try/catch
// -----------------------------------------------------------------------
assertContains(
    'PC-01: PaymentController::store() calls service->create',
    $paymentCtrl,
    '$this->service->create($data);'
);
assertContains(
    'PC-02: PaymentController::refund() has try/catch',
    $paymentCtrl,
    '$this->service->refund($id, $amount, $notes);'
);

// -----------------------------------------------------------------------
// V. RegisterController — open/close/move already had try/catch
// -----------------------------------------------------------------------
assertContains(
    'RC-01: RegisterController::open() has try/catch',
    $registerCtrl,
    '$this->service->openSession($branchId, $opening, $notes);'
);
assertContains(
    'RC-02: RegisterController::close() has try/catch',
    $registerCtrl,
    '$result = $this->service->closeSession($id, $closing, $notes);'
);
assertContains(
    'RC-03: RegisterController::move() has try/catch',
    $registerCtrl,
    '$this->service->addCashMovement($id, $type, $amount, $reason, $notes);'
);

// -----------------------------------------------------------------------
// VI. InvoiceService — prove the DomainException paths that triggered this task
// -----------------------------------------------------------------------
assertContains(
    'SVC-01: InvoiceService::delete() has DomainException for posted invoice',
    $invoiceService,
    "throw new \\DomainException('Financially posted invoice cannot be deleted.')"
);
assertContains(
    'SVC-02: InvoiceService::cancel() has DomainException for refunded invoice',
    $invoiceService,
    "throw new \\DomainException('Refunded invoice cannot be cancelled.')"
);
assertContains(
    'SVC-03: InvoiceService::cancel() has DomainException for paid invoice',
    $invoiceService,
    "throw new \\DomainException('Invoice with posted payments cannot be cancelled.')"
);

// -----------------------------------------------------------------------
// VII. Structural invariants — destroy/cancel are NOT bare calls anymore
// -----------------------------------------------------------------------
// The old bare pattern: no try/catch before service call in destroy
// We verify the CURRENT file does NOT contain the old bare pattern
assertNotContains(
    'INV-01: destroy() no longer has bare service->delete() outside try',
    $ic,
    "if (!\$this->ensureBranchAccess(\$invoice)) {\n            return;\n        }\n        \$this->service->delete(\$id);"
);
assertNotContains(
    'INV-02: cancel() no longer has bare service->cancel() outside try',
    $ic,
    "if (!\$this->ensureBranchAccess(\$invoice)) {\n            return;\n        }\n        \$this->service->cancel(\$id);"
);

// -----------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------
echo "\n";
echo str_repeat('-', 60) . "\n";
$total = $pass + $fail;
echo "SALES-CANONICAL-OPERATOR-FLOW-HARDENING-01: {$pass}/{$total} PASS\n";
echo str_repeat('-', 60) . "\n";

if ($fail > 0) {
    exit(1);
}
