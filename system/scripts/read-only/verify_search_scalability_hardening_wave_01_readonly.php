<?php

declare(strict_types=1);

/**
 * SEARCH-SCALABILITY-HARDENING-WAVE-01 — static contract for client directory + invoice list search paths.
 *
 * Client directory ({@see \Modules\Clients\Repositories\ClientRepository::applyClientListFilters}):
 * - Clearly-valid email → indexed c.email_lc = ? only (no LIKE bundle in the same predicate).
 * - Clearly phone (7–20 digit key) → indexed equality on phone_digits / phone_home_digits / phone_mobile_digits / phone_work_digits only.
 * - Otherwise → multi-column LIKE bundle (names + raw contact fields).
 *
 * Invoice list ({@see \Modules\Sales\Repositories\InvoiceRepository::appendListFilters}):
 * - invoice_number: legacy INV-[digits] or per-org ORG{id}-INV-[digits] → (i.invoice_number = ? OR same contains LIKE);
 *   other shapes stay contains LIKE only.
 * - client_phone: digit-normalizable input → (normalized(c.phone) = ? OR c.phone LIKE …); else LIKE only.
 * - client_name: unchanged contains LIKE (no safe equality fast path without parsing full name).
 *
 * Remaining cost: substring LIKE on names / non-canonical invoice strings; no full-text engine in this wave.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_search_scalability_hardening_wave_01_readonly.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$clientRepo = $system . '/modules/clients/repositories/ClientRepository.php';
$invoiceRepo = $system . '/modules/sales/repositories/InvoiceRepository.php';
$normalizer = $system . '/modules/clients/support/PublicContactNormalizer.php';

$c = (string) file_get_contents($clientRepo);
$i = (string) file_get_contents($invoiceRepo);
$n = (string) file_get_contents($normalizer);

$checks = [];

$checks['PublicContactNormalizer::sqlExprNormalizedPhoneDigits exists'] =
    str_contains($n, 'function sqlExprNormalizedPhoneDigits')
    && str_contains($n, 'COALESCE(');

$checks['ClientRepository search: digit fast path uses stored * _digits columns'] =
    str_contains($c, 'c.phone_digits = ?')
    && str_contains($c, 'c.phone_home_digits = ?')
    && str_contains($c, 'c.phone_work_digits = ?')
    && !str_contains($c, 'sqlExprNormalizedPhoneDigits');

$checks['ClientRepository search: email equality fast path (FILTER_VALIDATE_EMAIL)'] =
    str_contains($c, 'FILTER_VALIDATE_EMAIL') && str_contains($c, 'c.email_lc = ?');

$checks['ClientRepository search: free-text path uses LIKE bundle without OR-ing fast paths'] =
    str_contains($c, 'normalizePhoneDigitsForMatch')
    && str_contains($c, 'c.first_name LIKE ?')
    && !str_contains($c, ') OR \' . $likeSql . \')');

$checks['InvoiceRepository: canonical invoice_number equality fast path (INV- + ORG…-INV-)'] =
    str_contains($i, '^INV-[0-9]+$')
    && str_contains($i, '^ORG[0-9]+-INV-[0-9]+$')
    && str_contains($i, 'i.invoice_number = ?')
    && str_contains($i, 'i.invoice_number LIKE ?');

$checks['InvoiceRepository: client_phone digit path uses sqlExprNormalizedPhoneDigits(c.phone)'] =
    str_contains($i, "sqlExprNormalizedPhoneDigits('c.phone')") && str_contains($i, 'normalizePhoneDigitsForMatch');

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

echo PHP_EOL . 'Search scalability hardening wave 01 static checks passed.' . PHP_EOL;
exit(0);
