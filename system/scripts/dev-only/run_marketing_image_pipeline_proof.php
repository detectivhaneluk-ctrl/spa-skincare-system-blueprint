<?php

declare(strict_types=1);

/**
 * Dev-only host-side AUTO-DRAIN handoff proof for latest marketing image pipeline row.
 *
 * Usage (from system/):
 *   php scripts/dev-only/run_marketing_image_pipeline_proof.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;
use Modules\Media\Services\MediaUploadWorkerDevTrigger;
use Modules\Media\Services\MediaWorkerLocalRuntimeProbe;

$systemRoot = dirname(__DIR__, 2);
$phpMeta = MediaWorkerLocalRuntimeProbe::resolveCliPhpBinaryDetailed();
$nodeMeta = MediaWorkerLocalRuntimeProbe::resolveNodeBinaryDetailed();
$php = is_string($phpMeta['path'] ?? null) ? $phpMeta['path'] : null;
$node = is_string($nodeMeta['path'] ?? null) ? $nodeMeta['path'] : null;

echo "=== run_marketing_image_pipeline_proof ===\n";
echo 'resolved_php=' . ($php ?? 'null') . "\n";
echo 'resolved_php_source=' . (string) ($phpMeta['source'] ?? 'none') . "\n";
echo 'resolved_node=' . ($node ?? 'null') . "\n";
echo 'resolved_node_source=' . (string) ($nodeMeta['source'] ?? 'none') . "\n";

if ($php === null) {
    echo "VERDICT: AUTO-DRAIN-FAILED: php_cli_unresolved\n";
    exit(1);
}
if ($node === null) {
    echo "VERDICT: AUTO-DRAIN-FAILED: node_unresolved\n";
    exit(1);
}

$snapshotCmd = '"' . $php . '" "' . $systemRoot . '\\scripts\\read-only\\prove_marketing_image_end_to_end.php"';
echo "\n--- end-to-end snapshot ---\n";
passthru($snapshotCmd, $snapshotExit);

$db = app(\Core\App\Database::class);
$latest = $db->fetchOne(
    "SELECT i.id AS image_id, i.media_asset_id
     FROM marketing_gift_card_images i
     WHERE i.deleted_at IS NULL AND i.media_asset_id IS NOT NULL
     ORDER BY i.id DESC LIMIT 1"
);
if ($latest === null || (int) ($latest['media_asset_id'] ?? 0) <= 0) {
    echo "VERDICT: AUTO-DRAIN-FAILED: no_latest_marketing_asset\n";
    exit(1);
}
$assetId = (int) $latest['media_asset_id'];
$asset = $db->fetchOne("SELECT id,status FROM media_assets WHERE id = ? LIMIT 1", [$assetId]);
$job = $db->fetchOne(
    "SELECT id,status,attempts,error_message,locked_at FROM media_jobs
     WHERE media_asset_id = ? AND job_type = ?
     ORDER BY id DESC LIMIT 1",
    [$assetId, MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO]
);
$autoDrain = MediaUploadWorkerDevTrigger::readAutoDrainStateForAsset($assetId);
$drain = null;
$drainPath = base_path('storage/logs/media_dev_worker_drain.json');
if (is_file($drainPath)) {
    $raw = @file_get_contents($drainPath);
    $parsed = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($parsed)) {
        $drain = $parsed;
    }
}
$worker = MediaWorkerLocalRuntimeProbe::probeNodeImageWorkerProcess();

echo "\n--- auto-drain handoff truth ---\n";
echo 'latest_image_id=' . (int) ($latest['image_id'] ?? 0) . "\n";
echo 'asset_row=' . json_encode($asset, JSON_UNESCAPED_UNICODE) . "\n";
echo 'job_row=' . json_encode($job, JSON_UNESCAPED_UNICODE) . "\n";
echo 'auto_drain_state=' . json_encode($autoDrain, JSON_UNESCAPED_UNICODE) . "\n";
echo 'drain_last=' . json_encode($drain, JSON_UNESCAPED_UNICODE) . "\n";
echo "worker_process_detected={$worker}\n";

$assetStatus = (string) ($asset['status'] ?? '');
$requested = (bool) ($autoDrain['auto_drain_requested'] ?? false);
$started = (bool) ($autoDrain['auto_drain_started'] ?? false);
$failureReason = (string) ($autoDrain['auto_drain_failure_reason'] ?? '');
$drainTargetsAsset = is_array($drain) && (int) ($drain['asset_id'] ?? 0) === $assetId;
$claimed = ((int) ($job['attempts'] ?? 0) > 0) || ((string) ($job['status'] ?? '') === 'completed') || (($job['locked_at'] ?? null) !== null);

echo 'auto_drain_requested=' . ($requested ? 'yes' : 'no') . "\n";
echo 'auto_drain_started=' . ($started ? 'yes' : 'no') . "\n";
echo 'worker_claimed_target=' . ($claimed ? 'yes' : 'no') . "\n";
echo 'final_asset_status=' . $assetStatus . "\n";

if ($assetStatus === 'ready' && $requested && $started && $claimed) {
    echo "VERDICT: AUTO-DRAIN-WORKS\n";
    exit(0);
}
if (!$requested) {
    echo "VERDICT: AUTO-DRAIN-FAILED: auto_drain_not_requested\n";
    exit(2);
}
if (!$started) {
    echo 'VERDICT: AUTO-DRAIN-FAILED: ' . ($failureReason !== '' ? $failureReason : 'auto_drain_not_started') . "\n";
    exit(3);
}
if ($drainTargetsAsset && isset($drain['ok']) && $drain['ok'] === false) {
    echo 'VERDICT: AUTO-DRAIN-FAILED: ' . (string) ($drain['reason'] ?? 'drain_failed') . "\n";
    exit(4);
}
if ($assetStatus === 'failed') {
    echo 'VERDICT: AUTO-DRAIN-FAILED: ' . (string) ($job['error_message'] ?? 'asset_failed') . "\n";
    exit(5);
}
echo "VERDICT: AUTO-DRAIN-FAILED: started_but_not_reached_ready_yet\n";
exit(6);

