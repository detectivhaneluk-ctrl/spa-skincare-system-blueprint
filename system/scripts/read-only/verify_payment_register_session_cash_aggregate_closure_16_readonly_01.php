<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 — FOUNDATION-TENANT-REPOSITORY-CLOSURE-16 (**FND-TNT-27**): static proof that
 * {@see \Modules\Sales\Repositories\PaymentRepository::getCompletedCashTotalsByCurrencyForRegisterSession}
 * uses branch-derived tenant guard + register session JOIN + register + invoice-plane SQL fragments.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_payment_register_session_cash_aggregate_closure_16_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/sales/repositories/PaymentRepository.php';
$src = (string) file_get_contents($path);

$checks = [];

$checks['PaymentRepository: getCompletedCashTotalsByCurrencyForRegisterSession exists'] =
    str_contains($src, 'function getCompletedCashTotalsByCurrencyForRegisterSession');

$checks['PaymentRepository: register cash aggregate requires branch-derived org (fail-closed)'] =
    preg_match(
        '/function\s+getCompletedCashTotalsByCurrencyForRegisterSession[\s\S]*?requireBranchDerivedOrganizationIdForInvoicePlane\s*\(\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: register cash aggregate joins register_sessions rs'] =
    str_contains($src, 'INNER JOIN register_sessions rs ON rs.id = p.register_session_id');

$checks['PaymentRepository: register cash aggregate applies registerSessionClause(rs)'] =
    preg_match(
        '/function\s+getCompletedCashTotalsByCurrencyForRegisterSession[\s\S]*?registerSessionClause\s*\(\s*[\'"]rs[\'"]\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: register cash aggregate applies paymentByInvoiceExistsClause after session scope'] =
    preg_match(
        '/function\s+getCompletedCashTotalsByCurrencyForRegisterSession[\s\S]*?registerSessionClause\s*\(\s*[\'"]rs[\'"]\s*\)[\s\S]*?paymentByInvoiceExistsClause\s*\(\s*[\'"]p[\'"]\s*,\s*[\'"]si[\'"]\s*\)/',
        $src
    ) === 1;

$checks['PaymentRepository: register cash aggregate rejects non-positive session id'] =
    preg_match(
        '/function\s+getCompletedCashTotalsByCurrencyForRegisterSession[\s\S]*?if\s*\(\s*\$registerSessionId\s*<=\s*0\s*\)\s*\{[\s\S]*?return\s*\[\s*\]\s*;/',
        $src
    ) === 1;

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Payment register-session cash aggregate tenant closure (CLOSURE-16) checks passed.' . PHP_EOL;
exit(0);
