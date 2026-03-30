<?php

declare(strict_types=1);

/**
 * Backfill payments.currency where invoices.currency is empty: uses SettingsService::getEffectiveCurrencyCode(branch_id).
 * Run after migrations 062–064. Idempotent: skips rows already matching resolver.
 *
 * Usage: php system/scripts/repair_payments_currency_empty_invoice.php
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);
$settings = app(\Core\App\SettingsService::class);

$rows = $db->fetchAll(
    "SELECT p.id, p.currency, i.branch_id
     FROM payments p
     INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
     WHERE TRIM(COALESCE(i.currency, '')) = ''"
);

$updated = 0;
$skipped = 0;
foreach ($rows as $r) {
    $id = (int) ($r['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $bid = isset($r['branch_id']) && $r['branch_id'] !== '' && $r['branch_id'] !== null ? (int) $r['branch_id'] : null;
    $target = $settings->getEffectiveCurrencyCode($bid);
    $current = strtoupper(trim((string) ($r['currency'] ?? '')));
    if ($current === $target) {
        $skipped++;
        continue;
    }
    $db->query('UPDATE payments SET currency = ? WHERE id = ?', [$target, $id]);
    $updated++;
}

fwrite(STDOUT, "repair_payments_currency_empty_invoice: scanned=" . count($rows) . " updated={$updated} skipped_already_match={$skipped}\n");
exit(0);
