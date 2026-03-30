<?php

declare(strict_types=1);

/**
 * C-004-REGISTER-CASH-REFUND-POLARITY-TRUTH-RECOVERY-01: static proof that register-session cash aggregation
 * uses the same signed polarity as {@see \Modules\Sales\Repositories\PaymentRepository::getCompletedTotalByInvoiceId}
 * (refunds net negative for drawer math). No database.
 *
 * Usage:
 *   php system/scripts/read-only/verify_register_cash_refund_polarity_c004_01.php
 */

$base = dirname(__DIR__, 2);
$path = $base . '/modules/sales/repositories/PaymentRepository.php';
if (!is_file($path)) {
    fwrite(STDERR, "FAIL: missing {$path}\n");
    exit(1);
}

$src = (string) file_get_contents($path);

$invoiceNetPattern = '/getCompletedTotalByInvoiceId[\s\S]*?WHEN\s+p\.entry_type\s*=\s*[\'"]refund[\'"]\s+THEN\s+-p\.amount/';
$regStart = strpos($src, 'public function getCompletedCashTotalsByCurrencyForRegisterSession');
$regChunk = '';
if ($regStart !== false) {
    $regEnd = strpos($src, "\n    public function create(", $regStart);
    $regChunk = $regEnd !== false ? substr($src, $regStart, $regEnd - $regStart) : '';
}

$checks = [
    'getCompletedTotalByInvoiceId uses refund negation' => preg_match($invoiceNetPattern, $src) === 1,
    'getCompletedCashTotalsByCurrencyForRegisterSession uses same CASE (refund => -amount)' => $regChunk !== ''
        && str_contains($regChunk, "WHEN p.entry_type = 'refund' THEN -p.amount")
        && !str_contains($regChunk, 'COALESCE(SUM(p.amount), 0) AS total'),
    'register method still filters cash + completed + register_session_id' => $regChunk !== ''
        && str_contains($regChunk, "payment_method = 'cash'")
        && str_contains($regChunk, "status = 'completed'")
        && str_contains($regChunk, 'register_session_id'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'MISSING') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
