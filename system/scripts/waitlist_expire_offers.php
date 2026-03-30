<?php

declare(strict_types=1);

/**
 * Expire waitlist offers past offer_expires_at (revert to waiting) and chain re-offers when settings allow.
 * Decisions use DB offer timestamps only — not outbound email/SMS delivery state.
 * Cron-ready: host-level non-blocking flock (fast fail) plus MySQL GET_LOCK inside WaitlistService (all entry points).
 *
 * Exit codes: 0 sweep finished (see errors-count for per-row failures), 11 lock held (flock or DB), 1 fatal.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$lockDir = $systemPath . '/storage/locks';
$lockPath = $lockDir . '/waitlist_expiry_sweep.lock';

if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "waitlist-expiry-sweep: cannot create lock directory: {$lockDir}\n");
    exit(1);
}

$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false) {
    fwrite(STDERR, "waitlist-expiry-sweep: cannot open lock file: {$lockPath}\n");
    exit(1);
}

if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    fclose($lockFh);
    fwrite(STDOUT, "waitlist-expiry-sweep: lock-held (another instance running), exiting.\n");
    exit(11);
}

$exitCode = 0;
$stats = null;

try {
    $stats = app(\Modules\Appointments\Services\WaitlistService::class)->runWaitlistExpirySweep(null);
} catch (\Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, 'waitlist-expiry-sweep: fatal: ' . $e->getMessage() . PHP_EOL);
} finally {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}

if ($stats !== null && !empty($stats['lock_held'])) {
    fwrite(STDOUT, "waitlist-expiry-sweep: db-lock-held (another sweep active), exiting.\n");
    exit(11);
}

if ($stats !== null) {
    fwrite(STDOUT, 'candidates-examined=' . (int) ($stats['candidates_examined'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'offers-expired=' . (int) ($stats['offers_expired'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'chained-reoffer-attempts=' . (int) ($stats['chained_reoffer_attempts'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'chained-reoffers-created=' . (int) ($stats['chained_reoffers_created'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'chained-not-reoffered=' . (int) ($stats['chained_not_reoffered'] ?? 0) . PHP_EOL);
    $errors = $stats['errors'] ?? [];
    fwrite(STDOUT, 'errors-count=' . count($errors) . PHP_EOL);
    foreach ($errors as $err) {
        fwrite(STDOUT, 'error=' . str_replace(["\r", "\n"], ' ', (string) $err) . PHP_EOL);
    }
}

exit($exitCode);
