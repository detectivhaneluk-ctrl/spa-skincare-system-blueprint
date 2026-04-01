<?php

declare(strict_types=1);

namespace Core\Observability;

use Core\App\Config;
use Core\App\Database;
use Core\Contracts\SharedCacheInterface;
use Core\Runtime\Cache\SharedCacheMetrics;
use Core\Runtime\Jobs\RuntimeExecutionKeys;
use Core\Runtime\Jobs\RuntimeExecutionRegistry;
use Core\Runtime\Redis\RedisFactory;
use Core\Storage\Contracts\StorageProviderInterface;
use PDO;

/**
 * Read-only probes for consolidated backend health (cron/supervisor friendly).
 */
final class BackendHealthCollector
{
    public function __construct(
        private Database $database,
        private Config $config,
        private RuntimeExecutionRegistry $registry,
        private StorageProviderInterface $storage,
        private SharedCacheInterface $sharedCache,
        private SharedCacheMetrics $sharedCacheMetrics,
    ) {
    }

    public function collectAll(): BackendHealthReport
    {
        $layers = [
            $this->probeSession(),
            $this->probeStorage(),
            $this->probeRuntimeRegistry(),
            $this->probeImagePipeline(),
            $this->probeSharedCache(),
            $this->probeAsyncQueue(),
        ];

        $overall = BackendHealthStatus::HEALTHY;
        foreach ($layers as $layer) {
            $overall = BackendHealthStatus::worst($overall, $layer->status);
        }

        $exitCode = BackendHealthStatus::exitCodeForOverall($overall);
        $overallSummary = $this->buildOverallSummary($overall, $layers);

        return new BackendHealthReport($layers, $overall, $exitCode, $overallSummary);
    }

    private function buildOverallSummary(string $overall, array $layers): string
    {
        if ($overall === BackendHealthStatus::HEALTHY) {
            return 'All probed layers healthy.';
        }
        $parts = [];
        foreach ($layers as $layer) {
            if ($layer->status !== BackendHealthStatus::HEALTHY) {
                $parts[] = $layer->layer . '=' . $layer->status . '(' . implode(',', $layer->reasonCodes) . ')';
            }
        }
        $s = implode('; ', $parts);

        return strlen($s) > 500 ? substr($s, 0, 497) . '...' : $s;
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }

    private function tableExists(string $table): bool
    {
        $s = $this->pdo()->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $s->execute([$table]);

        return (bool) $s->fetchColumn();
    }

    private function probeSession(): BackendHealthLayerSnapshot
    {
        $driver = strtolower(trim((string) $this->config->get('session.driver', 'files')));
        $redisUrl = trim((string) $this->config->get('session.redis_url', ''));
        $env = strtolower((string) $this->config->get('app.env', 'production'));
        $isProduction = ($env === 'production');

        $errors = [];
        if ($driver === 'redis') {
            if (!extension_loaded('redis')) {
                $errors[] = BackendHealthReasonCodes::SESSION_REDIS_MISCONFIGURED;
            }
            if ($redisUrl === '') {
                $errors[] = BackendHealthReasonCodes::SESSION_REDIS_MISCONFIGURED;
            }
            if ($errors === [] && $redisUrl !== '') {
                try {
                    $redis = RedisFactory::connect($redisUrl);
                    $redis->ping();
                } catch (\Throwable) {
                    $errors[] = BackendHealthReasonCodes::SESSION_REDIS_UNREACHABLE;
                }
            }
        }

        if ($errors !== []) {
            $failed = $isProduction && $driver === 'redis';
            $status = $failed ? BackendHealthStatus::FAILED : BackendHealthStatus::DEGRADED;
            $summary = $failed
                ? 'Production session.redis misconfiguration or unreachable.'
                : 'Session redis configuration has warnings (non-production may still start sessions).';

            return new BackendHealthLayerSnapshot(BackendHealthLayer::SESSION, $status, $errors, $summary);
        }

        return new BackendHealthLayerSnapshot(
            BackendHealthLayer::SESSION,
            BackendHealthStatus::HEALTHY,
            [],
            'session.driver=' . $driver
        );
    }

    private function probeStorage(): BackendHealthLayerSnapshot
    {
        try {
            $driver = $this->storage->driverName();

            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::STORAGE,
                BackendHealthStatus::HEALTHY,
                [],
                'storage.driver=' . $driver
            );
        } catch (\Throwable $e) {
            $msg = $this->truncate($e->getMessage(), 240);

            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::STORAGE,
                BackendHealthStatus::FAILED,
                [BackendHealthReasonCodes::STORAGE_PROVIDER_INIT_FAILED],
                $msg
            );
        }
    }

    private function probeRuntimeRegistry(): BackendHealthLayerSnapshot
    {
        if (!$this->tableExists('runtime_execution_registry')) {
            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::RUNTIME_REGISTRY,
                BackendHealthStatus::FAILED,
                [BackendHealthReasonCodes::REGISTRY_TABLE_MISSING],
                'runtime_execution_registry table missing (migration 121).'
            );
        }

        $reasons = [];
        $rows = $this->registry->fetchAllForReadOnlyReport();
        foreach ($rows as $row) {
            $key = (string) ($row['execution_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $activeSince = $row['active_started_at'] ?? null;
            if ($activeSince !== null && $activeSince !== '') {
                $life = $row['active_heartbeat_at'] ?? null;
                if ($life === null || $life === '') {
                    $life = $activeSince;
                }
                $min = self::minutesSinceMysqlDatetime((string) $life);
                $staleMin = $this->registry->staleMinutesFor($key);
                if ($min >= $staleMin) {
                    $reasons[BackendHealthReasonCodes::REGISTRY_EXCLUSIVE_SLOT_STALE] = true;
                }
            }
            $lf = $row['last_failure_at'] ?? null;
            if ($lf !== null && $lf !== '' && self::minutesSinceMysqlDatetime((string) $lf) <= 90) {
                $reasons[BackendHealthReasonCodes::REGISTRY_RECENT_FAILURE] = true;
            }
        }

        $codes = array_keys($reasons);
        if ($codes !== []) {
            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::RUNTIME_REGISTRY,
                BackendHealthStatus::DEGRADED,
                $codes,
                'Registry shows stale exclusive slot and/or recent failure timestamps.'
            );
        }

        return new BackendHealthLayerSnapshot(
            BackendHealthLayer::RUNTIME_REGISTRY,
            BackendHealthStatus::HEALTHY,
            [],
            'runtime_execution_registry rows=' . count($rows)
        );
    }

    private function probeImagePipeline(): BackendHealthLayerSnapshot
    {
        if (!$this->tableExists('media_jobs') || !$this->tableExists('media_assets')) {
            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::IMAGE_PIPELINE,
                BackendHealthStatus::FAILED,
                [BackendHealthReasonCodes::IMAGE_MEDIA_TABLES_MISSING],
                'media_jobs or media_assets missing.'
            );
        }

        $pdo = $this->pdo();
        $jobType = 'process_photo_variants_v1';
        $staleLockMin = max(1, (int) env('IMAGE_JOB_STALE_LOCK_MINUTES', 30));

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM media_jobs j
             WHERE j.job_type = ?
               AND j.status = 'processing'
               AND j.locked_at IS NOT NULL
               AND j.locked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $st->execute([$jobType, $staleLockMin]);
        $staleProcessing = (int) $st->fetchColumn();

        $st2 = $pdo->prepare('SELECT COUNT(*) FROM media_jobs WHERE job_type = ? AND status = ?');
        $st2->execute([$jobType, 'pending']);
        $pending = (int) $st2->fetchColumn();

        $reasons = [];
        if ($staleProcessing > 0) {
            $reasons[] = BackendHealthReasonCodes::IMAGE_STALE_PROCESSING_JOBS;
        }

        $warnMin = max(1, (int) $this->config->get('runtime_jobs.image_worker_backlog_heartbeat_warn_minutes', 20));
        $workerKey = RuntimeExecutionKeys::WORKER_IMAGE_PIPELINE;
        $hb = null;
        if ($this->tableExists('runtime_execution_registry')) {
            $wh = $pdo->prepare(
                'SELECT last_heartbeat_at FROM runtime_execution_registry WHERE execution_key = ? LIMIT 1'
            );
            $wh->execute([$workerKey]);
            $hb = $wh->fetchColumn();
        }

        if ($pending > 0) {
            if ($hb === null || $hb === false || $hb === '') {
                $reasons[] = BackendHealthReasonCodes::IMAGE_BACKLOG_STALE_WORKER_HEARTBEAT;
            } else {
                $ts = strtotime((string) $hb);
                if ($ts === false || (time() - $ts) > ($warnMin * 60)) {
                    $reasons[] = BackendHealthReasonCodes::IMAGE_BACKLOG_STALE_WORKER_HEARTBEAT;
                }
            }
        }

        if ($reasons !== []) {
            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::IMAGE_PIPELINE,
                BackendHealthStatus::DEGRADED,
                $reasons,
                'pending=' . $pending . ' stale_processing=' . $staleProcessing
            );
        }

        return new BackendHealthLayerSnapshot(
            BackendHealthLayer::IMAGE_PIPELINE,
            BackendHealthStatus::HEALTHY,
            [],
            'image queue nominal (pending=' . $pending . ')'
        );
    }

    private function probeSharedCache(): BackendHealthLayerSnapshot
    {
        $this->sharedCache->get('__backend_health_probe_v1__');
        $snap = $this->sharedCacheMetrics->snapshot();
        $redisUrl = trim((string) $this->config->get('app.redis_url', ''));
        $env = strtolower((string) $this->config->get('app.env', 'production'));
        $isProduction = ($env === 'production');

        if ($redisUrl !== '' && !$snap['redis_effective']) {
            if ($isProduction) {
                return new BackendHealthLayerSnapshot(
                    BackendHealthLayer::SHARED_CACHE,
                    BackendHealthStatus::FAILED,
                    [BackendHealthReasonCodes::SHARED_CACHE_PRODUCTION_REDIS_REQUIRED],
                    'Production: REDIS_URL set but shared cache is not redis-effective (extension/connect).'
                );
            }

            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::SHARED_CACHE,
                BackendHealthStatus::DEGRADED,
                [BackendHealthReasonCodes::SHARED_CACHE_REDIS_CONFIGURED_NOT_EFFECTIVE],
                'REDIS_URL set but backend not redis_effective (noop/degraded).'
            );
        }

        return new BackendHealthLayerSnapshot(
            BackendHealthLayer::SHARED_CACHE,
            BackendHealthStatus::HEALTHY,
            [],
            'shared_cache backend=' . ($snap['backend'] ?? 'unknown')
        );
    }

    private static function minutesSinceMysqlDatetime(string $dt): int
    {
        $ts = strtotime($dt);

        return $ts === false ? 99999 : max(0, (int) floor((time() - $ts) / 60));
    }

    private function truncate(string $s, int $max): string
    {
        return strlen($s) <= $max ? $s : substr($s, 0, $max - 1) . '…';
    }

    /**
     * Probes runtime_async_jobs for dead-letter and stale-processing rows.
     *
     * Dead-letter jobs (status=dead) mean a job exhausted all retry attempts and
     * requires operator intervention — see OPS-WORKER-SUPERVISION-01.md § Dead-letter.
     * Stale processing rows (processing > 900 s) indicate a stuck or crashed worker.
     */
    private function probeAsyncQueue(): BackendHealthLayerSnapshot
    {
        if (!$this->tableExists('runtime_async_jobs')) {
            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::ASYNC_QUEUE,
                BackendHealthStatus::FAILED,
                [BackendHealthReasonCodes::ASYNC_QUEUE_TABLE_MISSING],
                'runtime_async_jobs table missing (migration 124).'
            );
        }

        try {
            $pdo = $this->pdo();

            $st = $pdo->prepare("SELECT COUNT(*) FROM runtime_async_jobs WHERE status = 'dead'");
            $st->execute();
            $dead = (int) $st->fetchColumn();

            $st2 = $pdo->prepare(
                "SELECT COUNT(*) FROM runtime_async_jobs WHERE status = 'processing' AND reserved_at IS NOT NULL AND reserved_at < DATE_SUB(NOW(3), INTERVAL 900 SECOND)"
            );
            $st2->execute();
            $stale = (int) $st2->fetchColumn();
        } catch (\Throwable $e) {
            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::ASYNC_QUEUE,
                BackendHealthStatus::DEGRADED,
                [BackendHealthReasonCodes::ASYNC_QUEUE_TABLE_MISSING],
                $this->truncate($e->getMessage(), 240)
            );
        }

        $reasons = [];
        if ($dead > 0) {
            $reasons[] = BackendHealthReasonCodes::ASYNC_QUEUE_DEAD_JOBS;
        }
        if ($stale > 0) {
            $reasons[] = BackendHealthReasonCodes::ASYNC_QUEUE_STALE_JOBS;
        }

        if ($reasons !== []) {
            return new BackendHealthLayerSnapshot(
                BackendHealthLayer::ASYNC_QUEUE,
                BackendHealthStatus::DEGRADED,
                $reasons,
                'async_queue dead=' . $dead . ' stale_processing=' . $stale
            );
        }

        return new BackendHealthLayerSnapshot(
            BackendHealthLayer::ASYNC_QUEUE,
            BackendHealthStatus::HEALTHY,
            [],
            'async_queue dead=0 stale=0'
        );
    }
}
