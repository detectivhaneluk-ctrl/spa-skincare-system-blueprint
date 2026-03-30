<?php

declare(strict_types=1);

/**
 * INVOICE-LIST-DATE-FILTER-SARGABILITY-HARDENING-01 — static contract for invoice list/count date filters.
 *
 * Behavior locked (equivalent to the legacy predicate):
 * - Effective sort date is calendar date of COALESCE(issued_at, created_at) (issued_at wins when non-null).
 * - issued_from: inclusive lower bound on that calendar date (DATE >= from).
 * - issued_to: inclusive upper bound on that calendar date (DATE <= to).
 *
 * Implementation: OR-split on bare i.issued_at / i.created_at with range bounds built from bound
 * parameters only (CONCAT/DATE_ADD on placeholders), so columns are sargable.
 *
 * Limitations (unchanged vs legacy DATE() on TIMESTAMP):
 * - Depends on MySQL TIMESTAMP/session timezone interpretation for literal datetime endpoints
 *   (same family of behavior as comparing TIMESTAMP to a date string).
 * - Wildly invalid Y-m-d strings are still caller/controller responsibility; legacy passed them through.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_invoice_list_date_filter_sargability_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$repoPath = $system . '/modules/sales/repositories/InvoiceRepository.php';
$m115 = $system . '/data/migrations/115_invoice_list_date_filter_sargability_indexes.sql';
$schemaPath = $system . '/data/full_project_schema.sql';

$repo = (string) file_get_contents($repoPath);
$m115Body = is_file($m115) ? (string) file_get_contents($m115) : '';
$schema = is_file($schemaPath) ? (string) file_get_contents($schemaPath) : '';

$checks = [];

$checks['InvoiceRepository: appendListFilters has no DATE( wrapper on invoice timestamp columns'] =
    !preg_match('/DATE\s*\(\s*COALESCE\s*\(\s*i\.issued_at/i', $repo);

$checks['InvoiceRepository: lower bound uses bare issued_at / created_at with CONCAT(?) start-of-day'] =
    (bool) preg_match('/i\.issued_at\s+IS\s+NOT\s+NULL\s+AND\s+i\.issued_at\s*>=\s*CONCAT\(\?/s', $repo)
    && (bool) preg_match('/i\.issued_at\s+IS\s+NULL\s+AND\s+i\.created_at\s*>=\s*CONCAT\(\?/s', $repo);

$checks['InvoiceRepository: upper bound uses DATE_ADD interval on bare issued_at / created_at'] =
    (bool) preg_match('/i\.issued_at\s+<\s*DATE_ADD\s*\(\s*CONCAT\s*\(\s*\?/s', $repo)
    && (bool) preg_match('/i\.created_at\s+<\s*DATE_ADD\s*\(\s*CONCAT\s*\(\s*\?/s', $repo);

$checks['Migration 115: branch + client issued_at supporting indexes'] = $m115Body !== ''
    && str_contains($m115Body, 'idx_invoices_branch_deleted_issued_at')
    && str_contains($m115Body, 'idx_invoices_client_deleted_issued_at');

$checks['full_project_schema.sql: lists both issued_at composites'] = str_contains($schema, 'idx_invoices_branch_deleted_issued_at')
    && str_contains($schema, 'idx_invoices_client_deleted_issued_at');

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

echo PHP_EOL . 'Invoice list date-filter sargability static checks passed.' . PHP_EOL;
exit(0);
