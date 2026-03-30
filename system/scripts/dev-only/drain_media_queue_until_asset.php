<?php

declare(strict_types=1);

/**
 * Dev-only: run the Node worker one job at a time until the given media_assets.id is
 * ready or failed (or --max-passes reached).
 *
 * Use when FIFO backlog exists: run_media_image_worker_once.php only processes the
 * oldest pending job per invocation.
 *
 * Usage (from system/):
 *   php scripts/dev-only/drain_media_queue_until_asset.php --asset-id=42
 *   php scripts/dev-only/drain_media_queue_until_asset.php --asset-id=42 --max-passes=200
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaWorkerLocalRuntimeProbe;
use Modules\Media\Services\MediaUploadWorkerDevTrigger;

$assetId = 0;
$maxPasses = 500;
$nodeBinaryArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--asset-id=')) {
        $assetId = (int) substr($arg, strlen('--asset-id='));
    }
    if (str_starts_with($arg, '--max-passes=')) {
        $maxPasses = max(1, (int) substr($arg, strlen('--max-passes=')));
    }
    if (str_starts_with($arg, '--node-binary=')) {
        $nodeBinaryArg = trim((string) substr($arg, strlen('--node-binary=')), "\"'");
    }
}

if ($assetId <= 0) {
    fwrite(STDERR, "Usage: php scripts/dev-only/drain_media_queue_until_asset.php --asset-id=N [--max-passes=500]\n");
    exit(1);
}
MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'drain script boot');
MediaUploadWorkerDevTrigger::recordAutoDrainEvent($assetId, [
    'auto_drain_requested' => true,
    'auto_drain_started' => true,
    'auto_drain_ts' => date('c'),
    'auto_drain_booted_at' => date('c'),
    'auto_drain_last_heartbeat_at' => date('c'),
    'auto_drain_pass_count' => 0,
    'auto_drain_last_worker_exit_code' => null,
    'auto_drain_last_observed_asset_status' => null,
    'auto_drain_last_observed_job_status' => null,
    'auto_drain_stdout_log_path' => MediaUploadWorkerDevTrigger::runtimeStdoutLogPathForAsset($assetId),
    'auto_drain_stderr_log_path' => MediaUploadWorkerDevTrigger::runtimeStderrLogPathForAsset($assetId),
    'auto_drain_failure_reason' => null,
    'auto_drain_state' => 'started',
]);

$db = app(\Core\App\Database::class);
$prev = getcwd();
$workerDir = null;
try {
    $asset = $db->fetchOne('SELECT id, status, stored_basename FROM media_assets WHERE id = ?', [$assetId]);
    if ($asset === null) {
        fwrite(STDERR, "media_assets not found: id={$assetId}\n");
        writeDrainDiagnostic($assetId, null, false, 'asset_missing', 'media_assets row missing');
        MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'asset missing before drain start');
        MediaUploadWorkerDevTrigger::recordAutoDrainEvent($assetId, [
            'auto_drain_started' => false,
            'auto_drain_failure_reason' => 'asset_missing',
            'auto_drain_state' => 'failed_before_worker',
            'auto_drain_last_heartbeat_at' => date('c'),
            'auto_drain_ts' => date('c'),
        ]);
        exit(1);
    }

    $job = $db->fetchOne(
        "SELECT id, status, attempts FROM media_jobs
         WHERE media_asset_id = ? AND job_type = ?
         ORDER BY id DESC LIMIT 1",
        [$assetId, \Modules\Media\Services\MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO]
    );
    $jobId = $job !== null ? (int) ($job['id'] ?? 0) : null;

    $status = (string) ($asset['status'] ?? '');
    if (in_array($status, ['ready', 'failed'], true)) {
        echo "media_assets_id={$assetId} already terminal status={$status}\n";
        writeDrainDiagnostic($assetId, $jobId, true, 'already_terminal', 'asset already terminal status=' . $status);
        MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'already terminal before loop status=' . $status);
        MediaUploadWorkerDevTrigger::recordAutoDrainEvent($assetId, [
            'auto_drain_started' => true,
            'auto_drain_failure_reason' => null,
            'auto_drain_state' => 'already_terminal',
            'auto_drain_last_heartbeat_at' => date('c'),
            'auto_drain_last_observed_asset_status' => $status,
            'auto_drain_last_observed_job_status' => $job !== null ? (string) ($job['status'] ?? '') : null,
            'auto_drain_ts' => date('c'),
        ]);
        exit(0);
    }

    $system = dirname(__DIR__, 2);
    $workerDir = realpath($system . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'image-pipeline');
    if ($workerDir === false || !is_file($workerDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'worker.mjs')) {
        fwrite(STDERR, "Worker directory not found.\n");
        markRuntimeBlocked($db, $assetId, $jobId, 'worker_file_missing', 'image-pipeline worker.mjs not found');
        exit(1);
    }

    $nodeMeta = MediaWorkerLocalRuntimeProbe::resolveNodeBinaryDetailed();
    $node = null;
    if (is_string($nodeBinaryArg) && $nodeBinaryArg !== '' && is_file($nodeBinaryArg)) {
        $node = $nodeBinaryArg;
        $nodeMeta = ['path' => $nodeBinaryArg, 'source' => 'drain_arg', 'detail' => 'from --node-binary'];
    } else {
        $node = is_string($nodeMeta['path'] ?? null) && $nodeMeta['path'] !== '' ? $nodeMeta['path'] : null;
    }
    if ($node === null) {
        markRuntimeBlocked($db, $assetId, $jobId, 'node_unresolved', 'could not resolve node binary');
        exit(1);
    }

    putenv('MEDIA_SYSTEM_ROOT=' . $system);
    putenv('WORKER_ONCE=1');
    putenv('WORKER_MAX_JOBS=1');
    putenv('DB_HOST=' . (string) env('DB_HOST', '127.0.0.1'));
    putenv('DB_PORT=' . (string) env('DB_PORT', '3306'));
    putenv('DB_DATABASE=' . (string) env('DB_DATABASE', ''));
    putenv('DB_USERNAME=' . (string) env('DB_USERNAME', ''));
    putenv('DB_PASSWORD=' . (string) env('DB_PASSWORD', ''));
    foreach (['IMAGE_JOB_STALE_LOCK_MINUTES', 'IMAGE_JOB_MAX_ATTEMPTS'] as $k) {
        $v = env($k, null);
        if ($v !== null && $v !== '') {
            putenv($k . '=' . (string) $v);
        }
    }

    chdir($workerDir);
    $cmd = escapeshellarg($node) . ' src/worker.mjs';
    MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'drain started cmd=' . $cmd);
    writeDrainDiagnostic(
        $assetId,
        $jobId,
        true,
        'drain_started',
        'drain start',
        [
            'node_binary' => $node,
            'node_resolution' => $nodeMeta,
            'worker_command' => $cmd,
            'worker_dir' => $workerDir,
        ]
    );

    for ($pass = 1; $pass <= $maxPasses; $pass++) {
        $row = $db->fetchOne('SELECT status FROM media_assets WHERE id = ?', [$assetId]);
        $st = (string) ($row['status'] ?? '');
        $jobSnapshot = $db->fetchOne(
            "SELECT id, status, attempts FROM media_jobs WHERE media_asset_id = ? AND job_type = ? ORDER BY id DESC LIMIT 1",
            [$assetId, \Modules\Media\Services\MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO]
        );
        $jobStatus = $jobSnapshot !== null ? (string) ($jobSnapshot['status'] ?? '') : null;
        MediaUploadWorkerDevTrigger::recordAutoDrainEvent($assetId, [
            'auto_drain_last_heartbeat_at' => date('c'),
            'auto_drain_pass_count' => $pass - 1,
            'auto_drain_last_observed_asset_status' => $st !== '' ? $st : null,
            'auto_drain_last_observed_job_status' => $jobStatus,
            'auto_drain_state' => 'started',
        ]);
        MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'pass ' . $pass . ' begin asset=' . $st . ' job=' . ($jobStatus ?? 'null'));
        if (in_array($st, ['ready', 'failed'], true)) {
            echo "drain_done pass={$pass} media_assets_id={$assetId} status={$st}\n";
            writeDrainDiagnostic($assetId, $jobId, true, 'drain_done', 'terminal status=' . $st, ['pass' => $pass]);
            MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'terminal reached before worker pass=' . $pass . ' asset=' . $st);
            MediaUploadWorkerDevTrigger::recordAutoDrainEvent($assetId, [
                'auto_drain_started' => true,
                'auto_drain_failure_reason' => null,
                'auto_drain_state' => 'finished_' . $st,
                'auto_drain_last_heartbeat_at' => date('c'),
                'auto_drain_pass_count' => $pass - 1,
                'auto_drain_last_observed_asset_status' => $st,
                'auto_drain_last_observed_job_status' => $jobStatus,
                'auto_drain_ts' => date('c'),
            ]);
            exit(0);
        }

        echo "--- drain pass {$pass}/{$maxPasses} (target asset still {$st}) ---\n";
        MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'pass ' . $pass . ' worker invoke: ' . $cmd);
        passthru($cmd, $exitCode);
        $rowAfter = $db->fetchOne('SELECT status FROM media_assets WHERE id = ?', [$assetId]);
        $stAfter = (string) ($rowAfter['status'] ?? '');
        $jobAfter = $db->fetchOne(
            "SELECT id, status, attempts FROM media_jobs WHERE media_asset_id = ? AND job_type = ? ORDER BY id DESC LIMIT 1",
            [$assetId, \Modules\Media\Services\MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO]
        );
        $jobStatusAfter = $jobAfter !== null ? (string) ($jobAfter['status'] ?? '') : null;
        MediaUploadWorkerDevTrigger::appendRuntimeLogLine(
            $assetId,
            'pass ' . $pass . ' worker exit=' . (string) $exitCode . ' asset=' . $stAfter . ' job=' . ($jobStatusAfter ?? 'null')
        );
        MediaUploadWorkerDevTrigger::recordAutoDrainEvent($assetId, [
            'auto_drain_last_heartbeat_at' => date('c'),
            'auto_drain_pass_count' => $pass,
            'auto_drain_last_worker_exit_code' => $exitCode,
            'auto_drain_last_observed_asset_status' => $stAfter !== '' ? $stAfter : null,
            'auto_drain_last_observed_job_status' => $jobStatusAfter,
            'auto_drain_state' => 'started',
        ]);
        if ($exitCode !== 0) {
            fwrite(STDERR, "worker exit code {$exitCode} at pass {$pass}\n");
            markRuntimeBlocked($db, $assetId, $jobId, 'worker_spawn_failure', 'worker command returned non-zero exit=' . $exitCode . ' pass=' . $pass);
            exit($exitCode);
        }
    }

    $row = $db->fetchOne('SELECT status FROM media_assets WHERE id = ?', [$assetId]);
    $st = (string) ($row['status'] ?? '');
    fwrite(STDERR, "drain exhausted: media_assets_id={$assetId} status={$st} after {$maxPasses} passes\n");
    writeDrainDiagnostic($assetId, $jobId, false, 'drain_exhausted', 'max passes exhausted with status=' . $st, ['max_passes' => $maxPasses]);
    MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'drain exhausted after passes=' . $maxPasses . ' asset=' . $st);
    MediaUploadWorkerDevTrigger::recordAutoDrainEvent($assetId, [
        'auto_drain_started' => true,
        'auto_drain_failure_reason' => 'drain_exhausted',
        'auto_drain_state' => 'failed_after_start',
        'auto_drain_last_heartbeat_at' => date('c'),
        'auto_drain_last_observed_asset_status' => $st !== '' ? $st : null,
        'auto_drain_ts' => date('c'),
    ]);
    exit(2);
} catch (\Throwable $e) {
    MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'drain exception: ' . $e->getMessage());
    writeDrainDiagnostic($assetId, null, false, 'drain_exception', $e->getMessage());
    MediaUploadWorkerDevTrigger::recordAutoDrainEvent($assetId, [
        'auto_drain_started' => true,
        'auto_drain_failure_reason' => 'drain_exception',
        'auto_drain_state' => 'failed_after_start',
        'auto_drain_last_heartbeat_at' => date('c'),
        'auto_drain_ts' => date('c'),
    ]);
    fwrite(STDERR, "drain exception: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_string($prev) && $prev !== '' && is_dir($prev)) {
        @chdir($prev);
    }
}

/**
 * @param array<string,mixed> $meta
 */
function writeDrainDiagnostic(int $assetId, ?int $jobId, bool $ok, string $reason, string $detail, array $meta = []): void
{
    $path = base_path('storage/logs/media_dev_worker_drain.json');
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $payload = [
        'ts' => date('c'),
        'asset_id' => $assetId,
        'job_id' => $jobId,
        'ok' => $ok,
        'reason' => $reason,
        'detail' => $detail,
    ];
    if ($meta !== []) {
        $payload['meta'] = $meta;
    }
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function markRuntimeBlocked(\Core\App\Database $db, int $assetId, ?int $jobId, string $reason, string $detail): void
{
    $msg = 'Local runtime blocked [' . $reason . ']: ' . $detail;
    $jobIdUse = $jobId;
    if ($jobIdUse === null || $jobIdUse <= 0) {
        $job = $db->fetchOne(
            "SELECT id FROM media_jobs
             WHERE media_asset_id = ? AND status IN ('pending','processing')
             ORDER BY id DESC LIMIT 1",
            [$assetId]
        );
        $jobIdUse = $job !== null ? (int) ($job['id'] ?? 0) : null;
    }
    if ($jobIdUse !== null && $jobIdUse > 0) {
        $db->query(
            "UPDATE media_jobs
             SET status = 'failed', locked_at = NULL, error_message = ?, updated_at = NOW()
             WHERE id = ?",
            [mb_substr($msg, 0, 1900), $jobIdUse]
        );
    }
    $db->query(
        "UPDATE media_assets
         SET status = CASE WHEN status = 'ready' THEN status ELSE 'failed' END, updated_at = NOW()
         WHERE id = ?",
        [$assetId]
    );
    writeDrainDiagnostic($assetId, $jobIdUse, false, $reason, $detail);
    MediaUploadWorkerDevTrigger::appendRuntimeLogLine($assetId, 'runtime blocked reason=' . $reason . ' detail=' . $detail);
    MediaUploadWorkerDevTrigger::recordAutoDrainEvent($assetId, [
        'auto_drain_started' => true,
        'auto_drain_failure_reason' => $reason,
        'auto_drain_state' => 'failed_after_start',
        'auto_drain_last_heartbeat_at' => date('c'),
        'auto_drain_ts' => date('c'),
    ]);
}
