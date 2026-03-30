<?php

declare(strict_types=1);

namespace Core\Runtime\Queue;

/**
 * Invokes the Node media image pipeline once (WORKER_ONCE + WORKER_MAX_JOBS=1) under the same DB env as the PHP app.
 */
final class RuntimeMediaImagePipelineCliRunner
{
    /**
     * @throws \RuntimeException when the worker script is missing or Node exits non-zero
     */
    public static function runOnce(string $systemRoot, ?int $mediaJobId = null): void
    {
        if (!function_exists('env')) {
            throw new \RuntimeException('env() is not defined; load bootstrap before the media bridge.');
        }

        $systemRootResolved = realpath($systemRoot);
        if ($systemRootResolved === false || !is_dir($systemRootResolved)) {
            throw new \RuntimeException('Invalid system root for media bridge: ' . $systemRoot);
        }

        $repoRoot = dirname($systemRootResolved);
        $workerDir = $repoRoot . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'image-pipeline';
        $script = $workerDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'worker.mjs';
        if (!is_file($script)) {
            throw new \RuntimeException('Media worker script missing: ' . $script);
        }

        $node = getenv('NODE_BINARY') ?: (PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node');

        $restore = [];
        $apply = static function (string $k, string $v) use (&$restore): void {
            $prev = getenv($k);
            $restore[$k] = $prev === false ? null : $prev;
            putenv($k . '=' . $v);
        };

        $apply('MEDIA_SYSTEM_ROOT', $systemRootResolved);
        $apply('WORKER_ONCE', '1');
        $apply('WORKER_MAX_JOBS', '1');
        $apply('DB_HOST', (string) env('DB_HOST', '127.0.0.1'));
        $apply('DB_PORT', (string) env('DB_PORT', '3306'));
        $apply('DB_DATABASE', (string) env('DB_DATABASE', ''));
        $apply('DB_USERNAME', (string) env('DB_USERNAME', ''));
        $apply('DB_PASSWORD', (string) env('DB_PASSWORD', ''));

        foreach (['IMAGE_JOB_STALE_LOCK_MINUTES', 'IMAGE_JOB_MAX_ATTEMPTS', 'WORKER_POLL_MS', 'WORKER_SKIP_HOUSEKEEPING_LOOP'] as $optKey) {
            $v = env($optKey, null);
            if ($v !== null && $v !== '') {
                $apply($optKey, (string) $v);
            }
        }

        if ($mediaJobId !== null && $mediaJobId > 0) {
            $apply('IMAGE_PIPELINE_FORCE_MEDIA_JOB_ID', (string) $mediaJobId);
        }

        $prevCwd = getcwd();
        $code = 0;
        try {
            if (!@chdir($workerDir)) {
                throw new \RuntimeException('Cannot chdir to media worker: ' . $workerDir);
            }
            $cmd = escapeshellarg($node) . ' ' . escapeshellarg('src/worker.mjs');
            passthru($cmd, $code);
        } finally {
            if ($prevCwd !== false) {
                @chdir($prevCwd);
            }
            foreach ($restore as $k => $prev) {
                if ($prev === null) {
                    putenv($k);
                } else {
                    putenv($k . '=' . $prev);
                }
            }
        }

        if ($code !== 0) {
            throw new \RuntimeException('Media image pipeline exited with code ' . $code);
        }
    }
}
