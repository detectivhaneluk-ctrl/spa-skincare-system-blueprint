<?php

declare(strict_types=1);

/**
 * Dev-only safe queue repair for stale media_jobs (process_photo_variants_v1).
 *
 * Canonical rule: repair at DB level only; worker still must run to reach ready (no fake ready).
 *
 * ## Selection rules (what gets repaired)
 *
 * 1) Stale processing
 *    - j.status = 'processing' AND j.locked_at < NOW() - INTERVAL stale_minutes MINUTE
 *    - Mutation if attempts < max_attempts: requeue → pending, clear lock, attempts+1, available_at=NOW()
 *    - Mutation if attempts >= max_attempts: fail job + fail asset (non-ready)
 *
 * 2) Nonclaimable pending (“stale pending” for proof counters)
 *    - j.status = 'pending' AND (j.attempts >= max_attempts OR a.status <> 'pending')
 *    - Mutation: fail job + fail asset (non-ready)
 *
 * 3) Failed job but asset still pending/processing
 *    - j.status = 'failed' AND a.status IN ('pending','processing')
 *    - Mutation: set a.status = 'failed' only (job already failed)
 *
 * ## Why FIFO can still look blocked after repair
 *
 * - Dry-run: no mutations; counts unchanged.
 * - Stale threshold: processing locks newer than IMAGE_JOB_STALE_LOCK_MINUTES are NOT selected;
 *   lower the threshold via env or --stale-minutes=.
 * - Healthy older pending jobs ahead: repair does not remove valid FIFO backlog; run the worker.
 * - Requeued jobs: after requeue, worker must claim again; if worker is offline, rows can go stale again.
 *
 * Usage (from system/):
 *   php scripts/dev-only/repair_media_queue_stale_jobs.php
 *   php scripts/dev-only/repair_media_queue_stale_jobs.php --apply
 *   php scripts/dev-only/repair_media_queue_stale_jobs.php --apply --stale-minutes=5
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;

$apply = in_array('--apply', $argv, true);
$staleMinutesOverride = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--stale-minutes=')) {
        $staleMinutesOverride = max(1, (int) substr($arg, strlen('--stale-minutes=')));
    }
}

$jobType = MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO;
$maxAttempts = max(1, (int) env('IMAGE_JOB_MAX_ATTEMPTS', 5));
$staleMinutes = $staleMinutesOverride ?? max(1, (int) env('IMAGE_JOB_STALE_LOCK_MINUTES', 30));
$db = app(\Core\App\Database::class);

/**
 * @return array{
 *   pending_total:int,
 *   processing_total:int,
 *   stale_processing:int,
 *   stale_pending:int,
 *   latest_marketing_ahead: list<array<string,mixed>>
 * }
 */
function repairStaleJobsSnapshot(\Core\App\Database $db, string $jobType, int $maxAttempts, int $staleMinutes): array
{
    $pendingTotal = (int) (($db->fetchOne("SELECT COUNT(*) AS c FROM media_jobs WHERE status='pending' AND job_type = ?", [$jobType]))['c'] ?? 0);
    $processingTotal = (int) (($db->fetchOne("SELECT COUNT(*) AS c FROM media_jobs WHERE status='processing' AND job_type = ?", [$jobType]))['c'] ?? 0);
    $staleProcessing = (int) (($db->fetchOne(
        "SELECT COUNT(*) AS c FROM media_jobs
         WHERE status='processing' AND job_type = ?
           AND locked_at IS NOT NULL
           AND locked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
        [$jobType, $staleMinutes]
    ))['c'] ?? 0);
    $stalePending = (int) (($db->fetchOne(
        "SELECT COUNT(*) AS c
         FROM media_jobs j
         INNER JOIN media_assets a ON a.id = j.media_asset_id
         WHERE j.job_type = ?
           AND j.status = 'pending'
           AND (j.attempts >= ? OR a.status <> 'pending')",
        [$jobType, $maxAttempts]
    ))['c'] ?? 0);

    $latest = $db->fetchOne(
        "SELECT i.id AS image_id, i.media_asset_id
         FROM marketing_gift_card_images i
         WHERE i.deleted_at IS NULL AND i.media_asset_id IS NOT NULL
         ORDER BY i.id DESC
         LIMIT 1"
    );
    $ahead = [];
    if ($latest !== null && (int) ($latest['media_asset_id'] ?? 0) > 0) {
        $assetId = (int) $latest['media_asset_id'];
        $job = $db->fetchOne(
            "SELECT id FROM media_jobs WHERE media_asset_id = ? AND job_type = ? ORDER BY id DESC LIMIT 1",
            [$assetId, $jobType]
        );
        $jobId = $job !== null ? (int) ($job['id'] ?? 0) : 0;
        if ($jobId > 0) {
            $ahead = $db->fetchAll(
                "SELECT j.id, j.media_asset_id, j.status, j.attempts, j.locked_at, a.status AS asset_status
                 FROM media_jobs j
                 LEFT JOIN media_assets a ON a.id = j.media_asset_id
                 WHERE j.job_type = ? AND j.id < ?
                   AND j.status IN ('pending','processing','failed')
                 ORDER BY j.id ASC
                 LIMIT 200",
                [$jobType, $jobId]
            );
        }
    }

    return [
        'pending_total' => $pendingTotal,
        'processing_total' => $processingTotal,
        'stale_processing' => $staleProcessing,
        'stale_pending' => $stalePending,
        'latest_marketing_ahead' => $ahead,
    ];
}

echo "=== repair_media_queue_stale_jobs ===\n";
echo 'mode=' . ($apply ? 'apply' : 'dry-run') . "\n";
echo "max_attempts={$maxAttempts}\n";
echo "stale_minutes={$staleMinutes}" . ($staleMinutesOverride !== null ? ' (override)' : ' (IMAGE_JOB_STALE_LOCK_MINUTES)') . "\n";

$before = repairStaleJobsSnapshot($db, $jobType, $maxAttempts, $staleMinutes);
echo "\n--- BEFORE ---\n";
echo 'count_pending_total=' . $before['pending_total'] . "\n";
echo 'count_processing_total=' . $before['processing_total'] . "\n";
echo 'count_stale_processing=' . $before['stale_processing'] . "\n";
echo 'count_stale_pending_nonclaimable=' . $before['stale_pending'] . "\n";
echo 'latest_marketing_jobs_ahead=' . json_encode($before['latest_marketing_ahead'], JSON_UNESCAPED_UNICODE) . "\n";

$staleProcessing = $db->fetchAll(
    "SELECT j.id, j.media_asset_id, j.attempts, j.locked_at, a.status AS asset_status
     FROM media_jobs j
     INNER JOIN media_assets a ON a.id = j.media_asset_id
     WHERE j.job_type = ?
       AND j.status = 'processing'
       AND j.locked_at IS NOT NULL
       AND j.locked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
     ORDER BY j.id ASC",
    [$jobType, $staleMinutes]
);
$nonclaimablePending = $db->fetchAll(
    "SELECT j.id, j.media_asset_id, j.attempts, j.error_message, a.status AS asset_status
     FROM media_jobs j
     INNER JOIN media_assets a ON a.id = j.media_asset_id
     WHERE j.job_type = ?
       AND j.status = 'pending'
       AND (j.attempts >= ? OR a.status <> 'pending')
     ORDER BY j.id ASC",
    [$jobType, $maxAttempts]
);
$failedNotClosed = $db->fetchAll(
    "SELECT j.id, j.media_asset_id, j.attempts, j.error_message, a.status AS asset_status
     FROM media_jobs j
     INNER JOIN media_assets a ON a.id = j.media_asset_id
     WHERE j.job_type = ?
       AND j.status = 'failed'
       AND a.status IN ('pending','processing')
     ORDER BY j.id ASC",
    [$jobType]
);

echo "\nselection_stale_processing_rows=" . count($staleProcessing) . "\n";
echo "selection_nonclaimable_pending_rows=" . count($nonclaimablePending) . "\n";
echo "selection_failed_not_closed_rows=" . count($failedNotClosed) . "\n";

$changes = [];

foreach ($staleProcessing as $r) {
    $jobId = (int) ($r['id'] ?? 0);
    $assetId = (int) ($r['media_asset_id'] ?? 0);
    $attempts = (int) ($r['attempts'] ?? 0);
    if ($attempts >= $maxAttempts) {
        $changes[] = ['type' => 'fail_stale_processing', 'job_id' => $jobId, 'asset_id' => $assetId];
        if ($apply) {
            $db->query(
                "UPDATE media_jobs
                 SET status='failed', locked_at=NULL, error_message=?, updated_at=NOW()
                 WHERE id=?",
                ['Stale processing exceeded retry budget; marked failed by local repair.', $jobId]
            );
            $db->query("UPDATE media_assets SET status='failed', updated_at=NOW() WHERE id=? AND status <> 'ready'", [$assetId]);
        }
        continue;
    }
    $changes[] = ['type' => 'requeue_stale_processing', 'job_id' => $jobId, 'asset_id' => $assetId];
    if ($apply) {
        $db->query(
            "UPDATE media_jobs
             SET status='pending', locked_at=NULL, attempts=attempts+1, error_message=?, available_at=NOW(), updated_at=NOW()
             WHERE id=?",
            ['Recovered stale processing lock; requeued by local repair.', $jobId]
        );
        $db->query("UPDATE media_assets SET status='pending', updated_at=NOW() WHERE id=? AND status <> 'ready'", [$assetId]);
    }
}

foreach ($nonclaimablePending as $r) {
    $jobId = (int) ($r['id'] ?? 0);
    $assetId = (int) ($r['media_asset_id'] ?? 0);
    $reason = ((int) ($r['attempts'] ?? 0) >= $maxAttempts)
        ? 'Pending exceeded max attempts; marked failed by local repair.'
        : 'Pending job nonclaimable (asset not pending); marked failed by local repair.';
    $changes[] = ['type' => 'fail_nonclaimable_pending', 'job_id' => $jobId, 'asset_id' => $assetId];
    if ($apply) {
        $db->query(
            "UPDATE media_jobs
             SET status='failed', locked_at=NULL, error_message=?, updated_at=NOW()
             WHERE id=?",
            [$reason, $jobId]
        );
        $db->query("UPDATE media_assets SET status='failed', updated_at=NOW() WHERE id=? AND status <> 'ready'", [$assetId]);
    }
}

foreach ($failedNotClosed as $r) {
    $jobId = (int) ($r['id'] ?? 0);
    $assetId = (int) ($r['media_asset_id'] ?? 0);
    $changes[] = ['type' => 'close_failed_asset_state', 'job_id' => $jobId, 'asset_id' => $assetId];
    if ($apply) {
        $db->query("UPDATE media_assets SET status='failed', updated_at=NOW() WHERE id=? AND status <> 'ready'", [$assetId]);
    }
}

echo "\nchanges_planned=" . json_encode($changes, JSON_UNESCAPED_UNICODE) . "\n";
echo 'changed_count=' . count($changes) . "\n";

$after = $before;
if ($apply) {
    $after = repairStaleJobsSnapshot($db, $jobType, $maxAttempts, $staleMinutes);
    echo "\n--- AFTER ---\n";
    echo 'count_pending_total=' . $after['pending_total'] . "\n";
    echo 'count_processing_total=' . $after['processing_total'] . "\n";
    echo 'count_stale_processing=' . $after['stale_processing'] . "\n";
    echo 'count_stale_pending_nonclaimable=' . $after['stale_pending'] . "\n";
    echo 'latest_marketing_jobs_ahead=' . json_encode($after['latest_marketing_ahead'], JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "\n--- AFTER (skipped: dry-run) ---\n";
}

// Verdict
if (!$apply) {
    echo "\nREPAIR-INCOMPLETE: dry-run — " . count($changes) . " change(s) planned; re-run with --apply for before/after proof and REPAIR-SUCCESS\n";
    exit(0);
}

$staleRemain = $after['stale_processing'] + $after['stale_pending'];
if ($staleRemain > 0) {
    echo "\nREPAIR-FAILED: stale rows remain after repair (stale_processing={$after['stale_processing']}, stale_pending_nonclaimable={$after['stale_pending']}); try --stale-minutes=5 or lower IMAGE_JOB_STALE_LOCK_MINUTES if locks are newer than {$staleMinutes} min; otherwise run worker to drain healthy backlog\n";
    exit(3);
}

echo "\nREPAIR-SUCCESS\n";
exit(0);
