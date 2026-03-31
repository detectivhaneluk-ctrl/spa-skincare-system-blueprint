<?php

declare(strict_types=1);

/**
 * Audit log archival cron — WAVE-04.
 *
 * Moves audit_log rows older than $retentionDays into audit_logs_archive in small batches.
 * Designed to be run by a cron/scheduler (e.g. nightly at 02:00 UTC).
 *
 * Algorithm:
 *  1. In batches of $batchSize, SELECT IDs of live rows older than retention cutoff.
 *  2. INSERT INTO audit_logs_archive SELECT those rows.
 *  3. DELETE those rows from audit_logs (after successful insert).
 *  4. Repeat until no more qualifying rows, or $maxBatches reached.
 *
 * Fail-safe:
 *  - Runs inside a per-batch transaction; rollback on any error.
 *  - Never deletes before confirming INSERT succeeded.
 *  - Logs via slog() when available.
 *
 * Usage: php system/scripts/run_audit_log_archival_cron.php [--dry-run]
 *
 * Env:
 *   AUDIT_LOG_RETENTION_DAYS  — rows older than this are archived (default: 365)
 *   AUDIT_LOG_BATCH_SIZE      — rows per batch (default: 500)
 *   AUDIT_LOG_MAX_BATCHES     — max batches per run, 0 = unlimited (default: 200)
 */

$repoRoot = dirname(__DIR__, 2);
require $repoRoot . '/system/bootstrap.php';
require $repoRoot . '/system/modules/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);

$retentionDays = (int) (getenv('AUDIT_LOG_RETENTION_DAYS') ?: 365);
$batchSize     = max(1, (int) (getenv('AUDIT_LOG_BATCH_SIZE') ?: 500));
$maxBatches    = (int) (getenv('AUDIT_LOG_MAX_BATCHES') ?: 200);

if ($retentionDays < 30) {
    fwrite(STDERR, "[audit_archival] REFUSE: AUDIT_LOG_RETENTION_DAYS={$retentionDays} is < 30. Refusing to archive so aggressively.\n");
    exit(1);
}

/** @var \Core\App\Database $db */
$db = app(\Core\App\Database::class);

$cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify("-{$retentionDays} days")
    ->format('Y-m-d H:i:s');

$batchId = \Ramsey\Uuid\Uuid::uuid4()->toString();

$totalArchived = 0;
$batchesRun    = 0;
$errors        = 0;

$logContext = static function (array $ctx) use ($batchId, $dryRun): void {
    $ctx['batch_id']  = $batchId;
    $ctx['dry_run']   = $dryRun;
    if (function_exists('slog')) {
        \slog('info', 'audit_archival', 'batch_progress', $ctx);
    } else {
        fwrite(STDERR, '[audit_archival] ' . json_encode($ctx) . "\n");
    }
};

$logContext([
    'event'           => 'archival_start',
    'retention_days'  => $retentionDays,
    'cutoff'          => $cutoff,
    'batch_size'      => $batchSize,
    'max_batches'     => $maxBatches,
]);

while (true) {
    if ($maxBatches > 0 && $batchesRun >= $maxBatches) {
        $logContext(['event' => 'max_batches_reached', 'batches_run' => $batchesRun]);
        break;
    }

    // Fetch the next batch of IDs to archive.
    $rows = $db->fetchAll(
        'SELECT id FROM audit_logs WHERE archived_at IS NULL AND created_at < ? LIMIT ?',
        [$cutoff, $batchSize]
    );

    if (empty($rows)) {
        break;
    }

    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($dryRun) {
        $logContext(['event' => 'dry_run_batch', 'ids_count' => count($ids)]);
        $batchesRun++;
        $totalArchived += count($ids);
        // In dry-run mode don't move more batches — just prove the first batch is selectable.
        break;
    }

    try {
        $db->beginTransaction();

        // Copy rows into the archive table.
        $db->query(
            "INSERT INTO audit_logs_archive
             SELECT * FROM audit_logs
             WHERE id IN ({$placeholders})",
            $ids
        );

        // Mark as archived on the source table (allows rollback window before hard delete).
        $db->query(
            "UPDATE audit_logs SET archived_at = NOW(), archival_batch_id = ?
             WHERE id IN ({$placeholders})",
            array_merge([$batchId], $ids)
        );

        // Hard delete only after both INSERT and UPDATE succeeded.
        $db->query(
            "DELETE FROM audit_logs WHERE id IN ({$placeholders}) AND archived_at IS NOT NULL",
            $ids
        );

        $db->commit();

        $batchesRun++;
        $totalArchived += count($ids);

        $logContext(['event' => 'batch_complete', 'rows_archived' => count($ids), 'total_archived' => $totalArchived]);

    } catch (\Throwable $e) {
        $db->rollback();
        $errors++;
        $logContext(['event' => 'batch_error', 'error' => $e->getMessage()]);

        if (function_exists('slog')) {
            \slog('error', 'audit_archival', 'batch_failed', ['error' => $e->getMessage(), 'batch_id' => $batchId]);
        }

        // Do not continue batching after a transaction failure — stop and let ops investigate.
        break;
    }
}

$logContext([
    'event'           => 'archival_complete',
    'total_archived'  => $totalArchived,
    'batches_run'     => $batchesRun,
    'errors'          => $errors,
]);

if ($errors > 0) {
    fwrite(STDERR, "[audit_archival] Completed with {$errors} error(s). See logs.\n");
    exit(1);
}

echo "[audit_archival] Done. Archived {$totalArchived} rows in {$batchesRun} batches.\n";
exit(0);
