<?php

declare(strict_types=1);

namespace Modules\Media\Services;

use Core\App\Database;

/**
 * Attaches media_jobs queue rows, pipeline runtime hints, and worker diagnostics to already-enriched
 * image library rows (gift card library, client profile photos, etc.).
 */
final class MediaImageLibraryStatusPayloadBuilder
{
    private const AUTO_DRAIN_HEARTBEAT_STALE_SECONDS = 60;

    private const AUTO_DRAIN_TERMINAL_STALE_SECONDS = 180;

    private ?bool $clientProfileImagesTableExists = null;

    public function __construct(
        private Database $db,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $images Rows already passed through per-library enrichImageRow (library_status, public_variant_url, …).
     *
     * @return array{images:list<array<string,mixed>>, worker_hint:array<string,mixed>}
     */
    public function buildForEnrichedImages(array $images): array
    {
        $assetIdsNeedingQueue = [];
        foreach ($images as $img) {
            $maid = isset($img['media_asset_id']) && $img['media_asset_id'] !== null && $img['media_asset_id'] !== ''
                ? (int) $img['media_asset_id']
                : 0;
            $lib = (string) ($img['library_status'] ?? 'legacy');
            if ($maid > 0 && $lib !== 'legacy' && $lib !== 'ready') {
                $assetIdsNeedingQueue[] = $maid;
            }
        }
        $assetIdsNeedingQueue = array_values(array_unique($assetIdsNeedingQueue));
        $jobsByAsset = $this->fetchLatestJobsByMediaAssetIds($assetIdsNeedingQueue);

        $maxPendingAhead = 0;
        $maxStaleAhead = 0;
        $processingNowCount = 0;
        $pendingAssetIds = [];
        $outImages = [];
        foreach ($images as $img) {
            $maid = isset($img['media_asset_id']) && $img['media_asset_id'] !== null && $img['media_asset_id'] !== ''
                ? (int) $img['media_asset_id']
                : 0;
            $lib = (string) ($img['library_status'] ?? 'legacy');
            $row = $img;
            if ($maid > 0 && $lib !== 'legacy' && $lib !== 'ready' && isset($jobsByAsset[$maid])) {
                $pendingAssetIds[] = $maid;
                $autoDrain = MediaUploadWorkerDevTrigger::readAutoDrainStateForAsset($maid);
                $job = $jobsByAsset[$maid];
                $jid = (int) ($job['id'] ?? 0);
                $pendingAhead = 0;
                $aheadHealth = [
                    'healthy_pending' => 0,
                    'stale_processing' => 0,
                    'failed_not_closed' => 0,
                    'nonclaimable_pending' => 0,
                    'deleted_from_library' => 0,
                ];
                $aheadJobs = [];
                if (($job['status'] ?? '') === 'pending' && $jid > 0) {
                    $analysis = $this->analyzeAheadJobsForCurrentPendingJob($jid);
                    $pendingAhead = (int) ($analysis['pending_jobs_ahead'] ?? 0);
                    $aheadHealth = is_array($analysis['ahead_health'] ?? null) ? $analysis['ahead_health'] : $aheadHealth;
                    $aheadJobs = is_array($analysis['ahead_jobs'] ?? null) ? $analysis['ahead_jobs'] : [];
                    if ($pendingAhead > $maxPendingAhead) {
                        $maxPendingAhead = $pendingAhead;
                    }
                    $staleLike = (int) ($aheadHealth['stale_processing'] ?? 0) + (int) ($aheadHealth['deleted_from_library'] ?? 0);
                    if ($staleLike > $maxStaleAhead) {
                        $maxStaleAhead = $staleLike;
                    }
                }
                if (($job['status'] ?? '') === 'processing' || (($job['locked_at'] ?? null) !== null && (string) ($job['locked_at'] ?? '') !== '')) {
                    $processingNowCount++;
                }
                $row['queue'] = [
                    'media_asset_id' => $maid,
                    'latest_job_id' => $jid > 0 ? $jid : null,
                    'latest_job_status' => (string) ($job['status'] ?? ''),
                    'attempts' => (int) ($job['attempts'] ?? 0),
                    'locked_at' => $job['locked_at'] ?? null,
                    'error_message' => $job['error_message'] ?? null,
                    'pending_jobs_ahead' => $pendingAhead,
                    'ahead_health' => $aheadHealth,
                    'ahead_jobs' => $aheadJobs,
                ];
                $row['auto_drain_requested'] = (bool) ($autoDrain['auto_drain_requested'] ?? false);
                $row['auto_drain_started'] = (bool) ($autoDrain['auto_drain_started'] ?? false);
                $row['auto_drain_asset_id'] = isset($autoDrain['auto_drain_asset_id']) ? (int) $autoDrain['auto_drain_asset_id'] : null;
                $row['auto_drain_ts'] = $autoDrain['auto_drain_ts'] ?? null;
                $row['auto_drain_failure_reason'] = $autoDrain['auto_drain_failure_reason'] ?? null;
                $row['auto_drain_state'] = $autoDrain['auto_drain_state'] ?? null;
                $runtime = $this->buildAssetRuntimeStatus($row, $autoDrain, $job);
                $row['pipeline_runtime'] = $runtime;
                $row['stalled_reason'] = $runtime['stalled_reason'];
            } else {
                $row['queue'] = null;
                $row['pipeline_runtime'] = null;
                $row['stalled_reason'] = null;
            }
            $outImages[] = $row;
        }

        $spawn = MediaUploadWorkerDevTrigger::readLastSpawnDiagnostics();
        $drain = $this->readLastDrainDiagnostics();
        $workerDetected = MediaWorkerLocalRuntimeProbe::probeNodeImageWorkerProcess();
        $probable = $this->computeProbableBlockReason(
            $spawn,
            $drain,
            $maxPendingAhead,
            $maxStaleAhead,
            $processingNowCount,
            $pendingAssetIds,
            $outImages,
            $workerDetected
        );
        $cliPhpResolved = MediaWorkerLocalRuntimeProbe::resolveCliPhpBinaryDetailed();
        $nodeResolved = MediaWorkerLocalRuntimeProbe::resolveNodeBinaryDetailed();
        $operatorCmd = 'php scripts/dev-only/run_media_image_worker_loop.php';
        if ($spawn !== null && isset($spawn['asset_id']) && (int) $spawn['asset_id'] > 0) {
            $operatorCmd = 'php scripts/dev-only/drain_media_queue_until_asset.php --asset-id=' . (int) $spawn['asset_id'];
        }
        $blockDetail = '';
        if (($probable === 'drain_failed' || $probable === 'drain_exhausted') && $drain !== null) {
            $blockDetail = (string) ($drain['reason'] ?? '');
            $detail = trim((string) ($drain['detail'] ?? ''));
            if ($detail !== '') {
                $blockDetail .= ($blockDetail !== '' ? ': ' : '') . $detail;
            }
        } elseif ($spawn !== null && isset($spawn['ok']) && $spawn['ok'] === false) {
            $blockDetail = (string) ($spawn['reason'] ?? 'spawn_failed');
            $detail = trim((string) ($spawn['detail'] ?? ''));
            if ($detail !== '') {
                $blockDetail .= ': ' . $detail;
            }
        }
        if ($probable === 'drain_failed' || $probable === 'drain_exhausted') {
            $targetDrainAsset = (int) ($drain['asset_id'] ?? 0);
            if ($targetDrainAsset > 0) {
                $operatorCmd = 'php scripts/dev-only/drain_media_queue_until_asset.php --asset-id=' . $targetDrainAsset;
            }
        } elseif ($probable === 'healthy_backlog') {
            $operatorCmd = 'php scripts/dev-only/run_media_image_worker_loop.php';
        }

        return [
            'images' => $outImages,
            'worker_hint' => [
                'worker_process_detected' => $workerDetected,
                'probable_block_reason' => $probable,
                'block_detail' => $blockDetail,
                'operator_command' => $operatorCmd,
                'large_fifo_backlog' => $maxPendingAhead >= 10,
                'max_pending_jobs_ahead' => $maxPendingAhead,
                'stale_processing_rows_ahead_non_blocking' => $maxStaleAhead,
                'processing_now_count' => $processingNowCount,
                'spawn_last' => $spawn,
                'drain_last' => [
                    'ok' => $drain['ok'] ?? null,
                    'reason' => $drain['reason'] ?? null,
                    'detail' => $drain['detail'] ?? null,
                    'asset_id' => $drain['asset_id'] ?? null,
                    'job_id' => $drain['job_id'] ?? null,
                ],
                'resolved_cli_php_binary' => $cliPhpResolved['path'] ?? null,
                'resolved_cli_php_source' => $cliPhpResolved['source'] ?? 'none',
                'resolved_node_binary' => $nodeResolved['path'] ?? null,
                'resolved_node_source' => $nodeResolved['source'] ?? 'none',
                'app_env' => (string) env('APP_ENV', 'production'),
            ],
        ];
    }

    /**
     * @param list<int> $assetIds
     *
     * @return array<int, array<string,mixed>>
     */
    private function fetchLatestJobsByMediaAssetIds(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT m1.* FROM media_jobs m1
             INNER JOIN (
               SELECT media_asset_id, MAX(id) AS max_id FROM media_jobs
               WHERE media_asset_id IN ($placeholders)
               GROUP BY media_asset_id
             ) t ON m1.media_asset_id = t.media_asset_id AND m1.id = t.max_id",
            $assetIds
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) ($r['media_asset_id'] ?? 0)] = $r;
        }

        return $map;
    }

    /**
     * @return array{pending_jobs_ahead:int,ahead_health:array<string,int>,ahead_jobs:list<array<string,mixed>>}
     */
    private function analyzeAheadJobsForCurrentPendingJob(int $jobId): array
    {
        $maxAttempts = max(1, (int) env('IMAGE_JOB_MAX_ATTEMPTS', 5));
        $staleMinutes = max(1, (int) env('IMAGE_JOB_STALE_LOCK_MINUTES', 30));

        if ($this->clientProfileImagesTableExists === null) {
            $row = $this->db->fetchOne(
                'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                ['client_profile_images']
            );
            $this->clientProfileImagesTableExists = isset($row['ok']) && (int) $row['ok'] === 1;
        }

        if ($this->clientProfileImagesTableExists) {
            $sql = "SELECT j.id, j.media_asset_id, j.status, j.attempts, j.locked_at, j.error_message,
                    a.status AS asset_status,
                    (
                      (SELECT COUNT(*) FROM marketing_gift_card_images mi WHERE mi.media_asset_id = j.media_asset_id AND mi.deleted_at IS NULL)
                    + (SELECT COUNT(*) FROM client_profile_images ci WHERE ci.media_asset_id = j.media_asset_id AND ci.deleted_at IS NULL)
                    ) AS active_library_refs,
                    (
                      (SELECT COUNT(*) FROM marketing_gift_card_images mi2 WHERE mi2.media_asset_id = j.media_asset_id AND mi2.deleted_at IS NOT NULL)
                    + (SELECT COUNT(*) FROM client_profile_images ci2 WHERE ci2.media_asset_id = j.media_asset_id AND ci2.deleted_at IS NOT NULL)
                    ) AS deleted_library_refs
             FROM media_jobs j
             LEFT JOIN media_assets a ON a.id = j.media_asset_id
             WHERE j.job_type = ? AND j.id < ?
               AND j.status IN ('pending','processing','failed')
             ORDER BY j.id ASC
             LIMIT 50";
        } else {
            $sql = "SELECT j.id, j.media_asset_id, j.status, j.attempts, j.locked_at, j.error_message,
                    a.status AS asset_status,
                    (SELECT COUNT(*) FROM marketing_gift_card_images mi WHERE mi.media_asset_id = j.media_asset_id AND mi.deleted_at IS NULL) AS active_library_refs,
                    (SELECT COUNT(*) FROM marketing_gift_card_images mi2 WHERE mi2.media_asset_id = j.media_asset_id AND mi2.deleted_at IS NOT NULL) AS deleted_library_refs
             FROM media_jobs j
             LEFT JOIN media_assets a ON a.id = j.media_asset_id
             WHERE j.job_type = ? AND j.id < ?
               AND j.status IN ('pending','processing','failed')
             ORDER BY j.id ASC
             LIMIT 50";
        }

        $rows = $this->db->fetchAll(
            $sql,
            [MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO, $jobId]
        );
        $health = [
            'healthy_pending' => 0,
            'stale_processing' => 0,
            'failed_not_closed' => 0,
            'nonclaimable_pending' => 0,
            'deleted_from_library' => 0,
        ];
        $ahead = [];
        $pendingClaimable = 0;
        foreach ($rows as $r) {
            $jst = (string) ($r['status'] ?? '');
            $ast = (string) ($r['asset_status'] ?? '');
            $attempts = (int) ($r['attempts'] ?? 0);
            $locked = $r['locked_at'] ?? null;
            $activeRefs = (int) ($r['active_library_refs'] ?? 0);
            $deletedRefs = (int) ($r['deleted_library_refs'] ?? 0);
            $isStaleProc = $jst === 'processing'
                && $locked !== null
                && (strtotime((string) $locked) !== false)
                && (time() - (int) strtotime((string) $locked) > ($staleMinutes * 60));
            $isFailedNotClosed = $jst === 'failed' && in_array($ast, ['pending', 'processing'], true);
            $isDeletedFromLibrary = $activeRefs === 0 && $deletedRefs > 0 && in_array($jst, ['pending', 'processing'], true);
            $isClaimablePending = $jst === 'pending' && $ast === 'pending' && $attempts < $maxAttempts;
            $isNonclaimablePending = $jst === 'pending' && !$isClaimablePending;
            if ($isClaimablePending) {
                $pendingClaimable++;
                $health['healthy_pending']++;
            }
            if ($isStaleProc) {
                $health['stale_processing']++;
            }
            if ($isFailedNotClosed) {
                $health['failed_not_closed']++;
            }
            if ($isNonclaimablePending) {
                $health['nonclaimable_pending']++;
            }
            if ($isDeletedFromLibrary) {
                $health['deleted_from_library']++;
            }
            $ahead[] = [
                'id' => (int) ($r['id'] ?? 0),
                'media_asset_id' => (int) ($r['media_asset_id'] ?? 0),
                'status' => $jst,
                'asset_status' => $ast,
                'attempts' => $attempts,
                'locked_at' => $locked,
                'health' => $isDeletedFromLibrary ? 'deleted-from-library'
                    : ($isStaleProc ? 'stale' : ($isFailedNotClosed ? 'failed-but-not-closed' : ($isClaimablePending ? 'healthy' : ($isNonclaimablePending ? 'nonclaimable' : 'other')))),
            ];
        }

        return [
            'pending_jobs_ahead' => $pendingClaimable,
            'ahead_health' => $health,
            'ahead_jobs' => $ahead,
        ];
    }

    /**
     * @param array<string,mixed>|null $spawn
     * @param list<array<string,mixed>> $imagesWithQueue
     * @param list<int> $pendingAssetIds
     *
     * @return 'healthy_backlog'|'stale_present_non_blocking'|'worker_not_running'|'drain_failed'|'drain_exhausted'|'processing'|'spawn_failed'|'unknown'
     */
    private function computeProbableBlockReason(
        ?array $spawn,
        ?array $drain,
        int $maxPendingAhead,
        int $maxStaleAhead,
        int $processingNowCount,
        array $pendingAssetIds,
        array $imagesWithQueue,
        string $workerDetected
    ): string {
        $hasPendingPipeline = false;
        foreach ($imagesWithQueue as $img) {
            $st = (string) ($img['library_status'] ?? '');
            if ($st === 'pending' || $st === 'processing') {
                $hasPendingPipeline = true;
                break;
            }
        }
        if (!$hasPendingPipeline) {
            return 'unknown';
        }

        $drainAssetId = $drain !== null ? (int) ($drain['asset_id'] ?? 0) : 0;
        $drainTargetsPending = $drainAssetId > 0 && in_array($drainAssetId, $pendingAssetIds, true);
        if ($drainTargetsPending && isset($drain['ok']) && $drain['ok'] === false) {
            $r = (string) ($drain['reason'] ?? '');
            if ($r === 'drain_exhausted') {
                return 'drain_exhausted';
            }

            return 'drain_failed';
        }

        if ($spawn !== null && isset($spawn['ok']) && $spawn['ok'] === false) {
            return 'spawn_failed';
        }

        if ($processingNowCount > 0) {
            return 'processing';
        }

        if ($maxPendingAhead > 0) {
            return 'healthy_backlog';
        }

        if ($maxStaleAhead > 0) {
            return 'stale_present_non_blocking';
        }

        $isLocal = (string) env('APP_ENV', 'production') === 'local';
        if ($isLocal && $workerDetected === 'no') {
            return 'worker_not_running';
        }

        return 'unknown';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readLastDrainDiagnostics(): ?array
    {
        $path = base_path('storage/logs/media_dev_worker_drain.json');
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $autoDrain
     * @param array<string,mixed> $job
     *
     * @return array<string,mixed>
     */
    private function buildAssetRuntimeStatus(array $row, ?array $autoDrain, array $job): array
    {
        $assetId = isset($row['media_asset_id']) ? (int) $row['media_asset_id'] : 0;
        $assetStatus = (string) ($row['media_asset_status'] ?? $row['library_status'] ?? '');
        $jobStatus = (string) ($job['status'] ?? '');
        $runtimeLogPath = null;
        $stdoutLogPath = null;
        $stderrLogPath = null;
        if (is_array($autoDrain) && is_string($autoDrain['auto_drain_stdout_log_path'] ?? null) && $autoDrain['auto_drain_stdout_log_path'] !== '') {
            $stdoutLogPath = (string) $autoDrain['auto_drain_stdout_log_path'];
        }
        if (is_array($autoDrain) && is_string($autoDrain['auto_drain_stderr_log_path'] ?? null) && $autoDrain['auto_drain_stderr_log_path'] !== '') {
            $stderrLogPath = (string) $autoDrain['auto_drain_stderr_log_path'];
        }
        if ($stdoutLogPath !== null) {
            $runtimeLogPath = $stdoutLogPath;
        } elseif ($assetId > 0) {
            $runtimeLogPath = MediaUploadWorkerDevTrigger::runtimeLogPathForAsset($assetId);
        }
        if ($stdoutLogPath === null && $assetId > 0) {
            $stdoutLogPath = MediaUploadWorkerDevTrigger::runtimeStdoutLogPathForAsset($assetId);
        }
        if ($stderrLogPath === null && $assetId > 0) {
            $stderrLogPath = MediaUploadWorkerDevTrigger::runtimeStderrLogPathForAsset($assetId);
        }

        $startedAtTs = $this->safeTs(is_array($autoDrain) ? ($autoDrain['auto_drain_ts'] ?? null) : null);
        $heartbeatTs = $this->safeTs(is_array($autoDrain) ? ($autoDrain['auto_drain_last_heartbeat_at'] ?? null) : null);
        $ageSinceStart = $startedAtTs !== null ? max(0, time() - $startedAtTs) : null;
        $heartbeatAge = $heartbeatTs !== null ? max(0, time() - $heartbeatTs) : null;
        $drainState = is_array($autoDrain) ? (string) ($autoDrain['auto_drain_state'] ?? '') : '';
        $spawnPid = is_array($autoDrain) && isset($autoDrain['auto_drain_spawn_pid']) ? (int) $autoDrain['auto_drain_spawn_pid'] : null;
        $lastExit = is_array($autoDrain) && array_key_exists('auto_drain_last_worker_exit_code', $autoDrain)
            ? (int) $autoDrain['auto_drain_last_worker_exit_code']
            : null;
        $passCount = is_array($autoDrain) && array_key_exists('auto_drain_pass_count', $autoDrain)
            ? max(0, (int) $autoDrain['auto_drain_pass_count'])
            : 0;

        $stalledReason = null;
        if ($assetStatus !== 'ready' && $assetStatus !== 'failed') {
            if ($drainState === 'spawned_but_boot_missing') {
                $stalledReason = 'spawned_but_boot_missing';
            } elseif ($drainState === 'started' && $startedAtTs !== null && $heartbeatTs === null && $ageSinceStart !== null && $ageSinceStart >= self::AUTO_DRAIN_HEARTBEAT_STALE_SECONDS) {
                $stalledReason = 'drain_started_but_no_heartbeat';
            } elseif ($drainState === 'started' && $heartbeatAge !== null && $heartbeatAge >= self::AUTO_DRAIN_HEARTBEAT_STALE_SECONDS) {
                $stalledReason = 'drain_started_but_no_terminal_update';
            } elseif ($drainState === 'started' && $passCount > 0 && $lastExit !== null && $lastExit === 0 && $assetStatus === 'pending' && $jobStatus === 'pending' && $heartbeatAge !== null && $heartbeatAge >= self::AUTO_DRAIN_TERMINAL_STALE_SECONDS) {
                $stalledReason = 'worker_exited_without_progress';
            }
            if (($drainState === 'failed_after_start' || $drainState === 'failed_before_start') && $stalledReason === null) {
                $stalledReason = 'drain_started_but_no_terminal_update';
            }
        }

        $operatorCmd = 'php scripts/read-only/prove_windows_media_launcher_boot.php --asset-id=' . $assetId;
        if ($stalledReason !== null) {
            $operatorCmd = 'php scripts/dev-only/drain_media_queue_until_asset.php --asset-id=' . $assetId . ' --max-passes=5000';
        }
        $diagnoseCmd = 'php scripts/read-only/prove_windows_media_launcher_boot.php --asset-id=' . $assetId;

        return [
            'asset_id' => $assetId > 0 ? $assetId : null,
            'asset_status' => $assetStatus,
            'job_status' => $jobStatus !== '' ? $jobStatus : null,
            'auto_drain_state' => $drainState !== '' ? $drainState : null,
            'spawn_pid' => $spawnPid,
            'heartbeat_age_seconds' => $heartbeatAge,
            'age_since_start_seconds' => $ageSinceStart,
            'pass_count' => $passCount,
            'last_worker_exit_code' => $lastExit,
            'runtime_log_path' => $runtimeLogPath,
            'stdout_log_path' => $stdoutLogPath,
            'stderr_log_path' => $stderrLogPath,
            'stalled_reason' => $stalledReason,
            'operator_command' => $operatorCmd,
            'diagnose_command' => $diagnoseCmd,
        ];
    }

    private function safeTs(mixed $iso): ?int
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        $ts = strtotime($iso);

        return $ts === false ? null : (int) $ts;
    }
}
