<?php

declare(strict_types=1);

/**
 * Narrow DB timing proof: runs a small set of parameterized queries N times, reports percentiles.
 * Does not load-test the app server — only DB round-trips + planner work.
 *
 *   php system/scripts/dev-only/db_hot_query_timing_proof_03.php
 *
 * Exit 0 = completed; 2 = DB unavailable; optional --iterations=30
 */

$systemRoot = dirname(__DIR__, 2);
require $systemRoot . '/bootstrap.php';

use Core\App\Application;
use Core\App\Database;

$iterations = 30;
foreach ($argv as $i => $arg) {
    if ($i > 0 && str_starts_with($arg, '--iterations=')) {
        $iterations = max(5, min(500, (int) trim(substr($arg, strlen('--iterations=')))));
    }
}

$db = Application::container()->get(Database::class);

$branchRow = $db->fetchOne('SELECT id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
if ($branchRow === null) {
    fwrite(STDERR, "No branch row; cannot run scoped queries.\n");
    exit(2);
}
$branchId = (int) $branchRow['id'];

$start = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
$end = (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d H:i:s');

$invoiceRow = $db->fetchOne('SELECT id FROM payments ORDER BY id DESC LIMIT 1');
$invoiceId = null;
if ($invoiceRow !== null) {
    $pid = (int) ($invoiceRow['id'] ?? 0);
    if ($pid > 0) {
        $pay = $db->fetchOne('SELECT invoice_id FROM payments WHERE id = ?', [$pid]);
        $invoiceId = isset($pay['invoice_id']) ? (int) $pay['invoice_id'] : null;
    }
}

$percentile = static function (array $sortedMs, float $p): float {
    $n = count($sortedMs);
    if ($n === 0) {
        return 0.0;
    }
    $idx = (int) ceil($p * $n) - 1;
    $idx = max(0, min($n - 1, $idx));

    return $sortedMs[$idx];
};

$run = static function (string $label, callable $fn, int $iterations) use ($percentile): void {
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $t0 = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $t0) / 1_000_000;
    }
    sort($times);
    $p50 = $percentile($times, 0.50);
    $p95 = $percentile($times, 0.95);
    $p99 = $percentile($times, 0.99);
    fwrite(STDOUT, sprintf(
        "%s iterations=%d p50_ms=%.3f p95_ms=%.3f p99_ms=%.3f\n",
        $label,
        $iterations,
        $p50,
        $p95,
        $p99
    ));
};

try {
    $run('select_branch_sample', static function () use ($db): void {
        $db->fetchOne('SELECT id, organization_id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
    }, $iterations);

    $run('appointments_branch_window', static function () use ($db, $branchId, $start, $end): void {
        $db->fetchOne(
            'SELECT COUNT(*) AS c FROM appointments WHERE branch_id = ? AND deleted_at IS NULL AND start_at >= ? AND start_at < ?',
            [$branchId, $start, $end]
        );
    }, $iterations);

    $run('clients_branch_active', static function () use ($db, $branchId): void {
        $db->fetchOne('SELECT COUNT(*) AS c FROM clients WHERE branch_id = ? AND deleted_at IS NULL', [$branchId]);
    }, $iterations);

    if ($invoiceId !== null && $invoiceId > 0) {
        $run('payments_by_invoice_tail', static function () use ($db, $invoiceId): void {
            $db->fetchAll('SELECT id, status, amount FROM payments WHERE invoice_id = ? ORDER BY id DESC LIMIT 5', [$invoiceId]);
        }, $iterations);
    } else {
        fwrite(STDOUT, "payments_by_invoice_tail skipped (no payment/invoice row)\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB proof failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

fwrite(STDOUT, "db_hot_query_timing_proof_03 done (provisional; not a 1000+ claim)\n");
