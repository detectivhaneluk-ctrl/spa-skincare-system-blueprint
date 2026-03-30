<?php

declare(strict_types=1);

/**
 * Process outbound_notification_messages: claim pending→processing, send, terminal status or bounded retry.
 * Email: `outbound.mail_transport` = log | php_mail | smtp (native socket; see config/outbound.php + .env).
 * SMS: cannot be enqueued from app code; any legacy SMS rows are skipped once by the worker (`OutboundChannelPolicy`). Stale `processing` rows are reclaimed to `pending`.
 *
 * FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01: non-blocking flock on this host + parallel-safe execution registry timestamps
 * (overlapping dispatchers on different hosts remain safe for row claims via SKIP LOCKED).
 *
 * Usage (from `system/` with PHP on PATH):
 *   php scripts/outbound_notifications_dispatch.php
 *   php scripts/outbound_notifications_dispatch.php 100   # batch limit
 *
 * Exit: 0 success, 11 lock held (another instance on this host), 1 fatal.
 *
 * Requires migration 082+ for `processing` / `claimed_at` and SKIP LOCKED (MySQL 8+ / MariaDB 10.6+).
 * Requires migration 121+ for `runtime_execution_registry` (registry failures are reported and exit non-zero).
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Core\Runtime\Jobs\RuntimeExecutionKeys;
use Core\Runtime\Jobs\RuntimeExecutionRegistry;

$limit = isset($argv[1]) && (int) $argv[1] > 0 ? (int) $argv[1] : 50;

$lockDir = $systemPath . '/storage/locks';
$lockPath = $lockDir . '/outbound_notifications_dispatch.lock';

if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "outbound-dispatch: cannot create lock directory: {$lockDir}\n");
    exit(1);
}

$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false) {
    fwrite(STDERR, "outbound-dispatch: cannot open lock file: {$lockPath}\n");
    exit(1);
}

if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    fclose($lockFh);
    fwrite(STDOUT, "outbound-dispatch: lock-held (another instance on this host), exiting.\n");
    exit(11);
}

/** @var RuntimeExecutionRegistry $registry */
$registry = app(RuntimeExecutionRegistry::class);
$key = RuntimeExecutionKeys::PHP_OUTBOUND_NOTIFICATIONS_DISPATCH;
$exitCode = 0;

try {
    $registry->recordParallelBatchStart($key);
    $stats = app(\Modules\Notifications\Services\OutboundNotificationDispatchService::class)->runBatch($limit);

    fwrite(STDOUT, 'outbound-dispatch processed=' . (int) ($stats['processed'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'outbound-dispatch captured_locally=' . (int) ($stats['captured_locally'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'outbound-dispatch handoff_accepted=' . (int) ($stats['handoff_accepted'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'outbound-dispatch dispatch_success_total=' . (int) ($stats['sent_legacy_compatible'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'outbound-dispatch failed=' . (int) ($stats['failed'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'outbound-dispatch skipped=' . (int) ($stats['skipped'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'outbound-dispatch reclaimed_stale=' . (int) ($stats['reclaimed_stale'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'outbound-dispatch retry_scheduled=' . (int) ($stats['retry_scheduled'] ?? 0) . PHP_EOL);

    $registry->completeParallelBatchSuccess($key);
} catch (\Throwable $e) {
    $exitCode = 1;
    try {
        $registry->completeParallelBatchFailure($key, $e->getMessage());
    } catch (\Throwable) {
    }
    fwrite(STDERR, 'outbound-dispatch: fatal: ' . $e->getMessage() . PHP_EOL);
} finally {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}

exit($exitCode);
