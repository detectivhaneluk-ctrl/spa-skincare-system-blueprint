<?php

declare(strict_types=1);

namespace Modules\Media\Services;

/**
 * Dev/local only: after an upload enqueues media_jobs, spawns a background PHP process that runs
 * {@see drain_media_queue_until_asset.php} for that asset so FIFO backlog completes without manual
 * repeated run_media_image_worker_once. Production (APP_ENV=production) never runs this.
 *
 * Gating: see MEDIA_DEV_AUTO_DRAIN_ON_UPLOAD and APP_ENV in .env.example.
 */
final class MediaUploadWorkerDevTrigger
{
    private const SPAWN_LOG_BASENAME = 'media_dev_worker_spawn.json';
    private const AUTO_DRAIN_STATE_DIR = 'storage/logs/media_dev_auto_drain_assets';
    private const AUTO_DRAIN_RUNTIME_LOG_DIR = 'storage/logs/media_dev_auto_drain_runtime';
    private const BOOT_PROBE_TIMEOUT_MS = 5000;
    private const BOOT_PROBE_INTERVAL_MS = 250;

    public function maybeSpawnAfterUpload(int $assetId): void
    {
        if ($assetId <= 0) {
            return;
        }
        if ($this->isProduction()) {
            return;
        }
        if (!$this->isAutoDrainEnabled()) {
            return;
        }
        self::recordAutoDrainEvent($assetId, [
            'auto_drain_requested' => true,
            'auto_drain_started' => false,
            'auto_drain_asset_id' => $assetId,
            'auto_drain_stdout_log_path' => self::runtimeStdoutLogPathForAsset($assetId),
            'auto_drain_stderr_log_path' => self::runtimeStderrLogPathForAsset($assetId),
            'auto_drain_ts' => date('c'),
            'auto_drain_failure_reason' => null,
            'auto_drain_state' => 'requested',
            'auto_drain_spawn_pid' => null,
            'auto_drain_spawn_accepted_at' => null,
            'auto_drain_booted_at' => null,
            'auto_drain_last_heartbeat_at' => null,
        ]);
        $script = base_path('scripts/dev-only/drain_media_queue_until_asset.php');
        if (!is_file($script)) {
            $this->recordSpawnOutcome($assetId, false, 'drain_script_missing', 'drain script not found', null, null, null, null);
            self::recordAutoDrainEvent($assetId, [
                'auto_drain_started' => false,
                'auto_drain_failure_reason' => 'drain_script_missing',
                'auto_drain_stdout_log_path' => self::runtimeStdoutLogPathForAsset($assetId),
                'auto_drain_stderr_log_path' => self::runtimeStderrLogPathForAsset($assetId),
                'auto_drain_state' => 'failed_before_start',
                'auto_drain_ts' => date('c'),
            ]);

            return;
        }

        $phpResolved = MediaWorkerLocalRuntimeProbe::resolveCliPhpBinaryDetailed();
        $php = is_string($phpResolved['path'] ?? null) ? $phpResolved['path'] : null;
        if ($php === null) {
            $this->recordSpawnOutcome(
                $assetId,
                false,
                'cli_php_unresolved',
                'Could not resolve a CLI PHP binary (' . (string) ($phpResolved['detail'] ?? 'unknown') . ').',
                null,
                null,
                null,
                null,
                [
                    'php_resolution' => $phpResolved,
                    'node_resolution' => MediaWorkerLocalRuntimeProbe::resolveNodeBinaryDetailed(),
                ]
            );
            self::recordAutoDrainEvent($assetId, [
                'auto_drain_started' => false,
                'auto_drain_failure_reason' => 'cli_php_unresolved',
                'auto_drain_stdout_log_path' => self::runtimeStdoutLogPathForAsset($assetId),
                'auto_drain_stderr_log_path' => self::runtimeStderrLogPathForAsset($assetId),
                'auto_drain_state' => 'failed_before_start',
                'auto_drain_ts' => date('c'),
            ]);

            return;
        }

        $nodeResolved = MediaWorkerLocalRuntimeProbe::resolveNodeBinaryDetailed();
        $node = is_string($nodeResolved['path'] ?? null) ? $nodeResolved['path'] : null;
        $result = $this->spawnDetached($php, $script, $assetId, $node);
        if (!$result['ok']) {
            $this->recordSpawnOutcome(
                $assetId,
                false,
                $result['reason'] ?? 'spawn_failed',
                (string) ($result['detail'] ?? 'spawn failed'),
                $php,
                $node,
                $result['launched_command'] ?? null,
                $result['spawn_exit_code'] ?? null,
                [
                    'php_resolution' => $phpResolved,
                    'node_resolution' => $nodeResolved,
                ]
            );
            self::recordAutoDrainEvent($assetId, [
                'auto_drain_started' => false,
                'auto_drain_failure_reason' => (string) ($result['reason'] ?? 'spawn_failed'),
                'auto_drain_stdout_log_path' => self::runtimeStdoutLogPathForAsset($assetId),
                'auto_drain_stderr_log_path' => self::runtimeStderrLogPathForAsset($assetId),
                'auto_drain_state' => 'failed_before_start',
                'auto_drain_ts' => date('c'),
            ]);

            return;
        }

        $this->recordSpawnOutcome(
            $assetId,
            true,
            'ok',
            'background drain spawned',
            $php,
            $node,
            $result['launched_command'] ?? null,
            $result['spawn_exit_code'] ?? null,
            [
                'php_resolution' => $phpResolved,
                'node_resolution' => $nodeResolved,
            ]
        );
        $spawnAcceptedAt = date('c');
        self::recordAutoDrainEvent($assetId, [
            'auto_drain_started' => true,
            'auto_drain_failure_reason' => null,
            'auto_drain_stdout_log_path' => self::runtimeStdoutLogPathForAsset($assetId),
            'auto_drain_stderr_log_path' => self::runtimeStderrLogPathForAsset($assetId),
            'auto_drain_state' => 'spawned',
            'auto_drain_spawn_pid' => isset($result['spawn_pid']) ? (int) $result['spawn_pid'] : null,
            'auto_drain_spawn_accepted_at' => $spawnAcceptedAt,
            'auto_drain_ts' => date('c'),
        ]);

        if (!$this->probeBootMarker($assetId)) {
            self::recordAutoDrainEvent($assetId, [
                'auto_drain_started' => true,
                'auto_drain_failure_reason' => 'boot_marker_missing_after_spawn',
                'auto_drain_state' => 'spawned_but_boot_missing',
                'auto_drain_ts' => date('c'),
            ]);
        }
    }

    /**
     * Last spawn attempt (success or failure) for observability — read-only JSON file.
     *
     * @return array<string, mixed>|null
     */
    public static function readLastSpawnDiagnostics(): ?array
    {
        $path = self::spawnLogPath();
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
     * @return array<string,mixed>|null
     */
    public static function readAutoDrainStateForAsset(int $assetId): ?array
    {
        if ($assetId <= 0) {
            return null;
        }
        $path = self::autoDrainStatePath($assetId);
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
     * @param array<string,mixed> $event
     */
    public static function recordAutoDrainEvent(int $assetId, array $event): void
    {
        if ($assetId <= 0) {
            return;
        }
        $path = self::autoDrainStatePath($assetId);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $base = self::readAutoDrainStateForAsset($assetId);
        if (!is_array($base)) {
            $base = [
                'auto_drain_requested' => false,
                'auto_drain_started' => false,
                'auto_drain_asset_id' => $assetId,
                'auto_drain_ts' => null,
                'auto_drain_failure_reason' => null,
                'auto_drain_state' => 'unknown',
            ];
        }
        foreach ($event as $k => $v) {
            $base[$k] = $v;
        }
        $base['auto_drain_asset_id'] = $assetId;
        $base['updated_at'] = date('c');
        @file_put_contents($path, json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public static function runtimeStdoutLogPathForAsset(int $assetId): string
    {
        return base_path(self::AUTO_DRAIN_RUNTIME_LOG_DIR . DIRECTORY_SEPARATOR . 'asset-' . $assetId . '.out.log');
    }

    public static function runtimeStderrLogPathForAsset(int $assetId): string
    {
        return base_path(self::AUTO_DRAIN_RUNTIME_LOG_DIR . DIRECTORY_SEPARATOR . 'asset-' . $assetId . '.err.log');
    }

    public static function runtimeLogPathForAsset(int $assetId): string
    {
        return self::runtimeStdoutLogPathForAsset($assetId);
    }

    public static function appendRuntimeLogLine(int $assetId, string $line): void
    {
        if ($assetId <= 0) {
            return;
        }
        $path = self::runtimeLogPathForAsset($assetId);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($path, '[' . date('c') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }

    private function isProduction(): bool
    {
        return (string) env('APP_ENV', 'production') === 'production';
    }

    /**
     * When APP_ENV=local and unset: ON (reliable local default).
     * Explicit MEDIA_DEV_AUTO_DRAIN_ON_UPLOAD=0|false: OFF.
     * Explicit MEDIA_DEV_AUTO_DRAIN_ON_UPLOAD=1|true: ON (any non-production).
     * Unset on staging/other: OFF (avoid surprise background PHP).
     */
    private function isAutoDrainEnabled(): bool
    {
        $explicit = env('MEDIA_DEV_AUTO_DRAIN_ON_UPLOAD', null);
        if ($explicit === '0' || $explicit === 'false') {
            return false;
        }
        if ($explicit === '1' || $explicit === 'true') {
            return true;
        }
        if ((string) env('APP_ENV', 'production') === 'local') {
            return true;
        }

        return false;
    }

    /**
     * Detached spawn only: must not block the HTTP request until the drain script finishes.
     *
     * @return array{ok:bool, reason?:string, detail?:string}
     */
    private function spawnDetached(string $phpBinary, string $scriptPath, int $assetId, ?string $nodeBinary): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->spawnDetachedWindows($phpBinary, $scriptPath, $assetId, $nodeBinary);
        }

        $envPrefix = '';
        if ($nodeBinary !== null && $nodeBinary !== '') {
            $envPrefix = 'NODE_BINARY=' . escapeshellarg($nodeBinary) . ' ';
        }
        $line = sprintf(
            '%s%s %s --asset-id=%d --max-passes=5000%s > /dev/null 2>&1 &',
            $envPrefix,
            escapeshellarg($phpBinary),
            escapeshellarg($scriptPath),
            $assetId,
            $nodeBinary !== null ? ' --node-binary=' . escapeshellarg($nodeBinary) : ''
        );
        @exec($line);

        return ['ok' => true, 'launched_command' => $line, 'spawn_exit_code' => 0];
    }

    /**
     * @return array{ok:bool, reason?:string, detail?:string}
     */
    private function spawnDetachedWindows(string $phpBinary, string $scriptPath, int $assetId, ?string $nodeBinary): array
    {
        $runtimeStdoutLogPath = self::runtimeStdoutLogPathForAsset($assetId);
        $runtimeStderrLogPath = self::runtimeStderrLogPathForAsset($assetId);
        $runtimeDir = dirname($runtimeStdoutLogPath);
        if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
            return [
                'ok' => false,
                'reason' => 'runtime_log_dir_unwritable',
                'detail' => 'Could not create runtime log directory for detached drain process',
                'launched_command' => null,
                'spawn_exit_code' => null,
            ];
        }
        self::appendRuntimeLogLine($assetId, 'spawn request received (windows detached launch)');

        $args = [
            $scriptPath,
            '--asset-id=' . $assetId,
            '--max-passes=5000',
        ];
        if ($nodeBinary !== null && $nodeBinary !== '') {
            $args[] = '--node-binary=' . $nodeBinary;
        }
        $argList = '@(' . implode(', ', array_map(static fn(string $v): string => self::quoteWinPsSingle($v), $args)) . ')';
        $psInner = "& { \$ErrorActionPreference = 'Stop'; \$p = Start-Process -ErrorAction Stop -PassThru -FilePath "
            . self::quoteWinPsSingle($phpBinary)
            . ' -ArgumentList '
            . $argList
            . ' -WindowStyle Hidden -RedirectStandardOutput '
            . self::quoteWinPsSingle($runtimeStdoutLogPath)
            . ' -RedirectStandardError '
            . self::quoteWinPsSingle($runtimeStderrLogPath)
            . '; [Console]::Out.WriteLine([string]$p.Id); }';
        $line = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '
            . self::quoteWinArg($psInner);
        @exec($line, $out, $exit);

        if (!is_int($exit) || $exit !== 0) {
            self::appendRuntimeLogLine($assetId, 'windows detached launcher failed exit=' . (is_int($exit) ? (string) $exit : 'null'));
            return [
                'ok' => false,
                'reason' => 'spawn_exit_nonzero',
                'detail' => 'powershell Start-Process launcher exited non-zero',
                'launched_command' => $line,
                'spawn_exit_code' => is_int($exit) ? $exit : null,
            ];
        }

        $pid = null;
        foreach ($out as $lineOut) {
            $trim = trim((string) $lineOut);
            if ($trim !== '' && ctype_digit($trim)) {
                $pid = (int) $trim;
            }
        }
        if ($pid === null || $pid <= 0) {
            self::appendRuntimeLogLine($assetId, 'windows detached launcher accepted shell but returned no pid');
            return [
                'ok' => false,
                'reason' => 'spawn_pid_missing',
                'detail' => 'powershell Start-Process did not return process id',
                'launched_command' => $line,
                'spawn_exit_code' => $exit,
            ];
        }

        self::appendRuntimeLogLine($assetId, 'windows detached launcher exited=' . (string) $exit . ' (spawn accepted pid=' . $pid . ')');

        return ['ok' => true, 'launched_command' => $line, 'spawn_exit_code' => $exit, 'spawn_pid' => $pid];
    }

    private function probeBootMarker(int $assetId): bool
    {
        $deadline = microtime(true) + ((float) self::BOOT_PROBE_TIMEOUT_MS / 1000.0);
        do {
            $state = self::readAutoDrainStateForAsset($assetId);
            if (is_array($state)) {
                $bootedAt = (string) ($state['auto_drain_booted_at'] ?? '');
                if ($bootedAt !== '') {
                    return true;
                }
            }
            usleep(self::BOOT_PROBE_INTERVAL_MS * 1000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeSpawnLogFile(array $data): void
    {
        $path = self::spawnLogPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $data['ts'] = date('c');
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private static function spawnLogPath(): string
    {
        return base_path('storage/logs/' . self::SPAWN_LOG_BASENAME);
    }

    private static function autoDrainStatePath(int $assetId): string
    {
        return base_path(self::AUTO_DRAIN_STATE_DIR . DIRECTORY_SEPARATOR . 'asset-' . $assetId . '.json');
    }

    private function recordSpawnOutcome(
        int $assetId,
        bool $ok,
        string $reason,
        string $detail,
        ?string $phpBinary,
        ?string $nodeBinary,
        ?string $launchedCommand,
        ?int $spawnExitCode,
        ?array $resolutionMeta = null
    ): void {
        $payload = [
            'asset_id' => $assetId,
            'ok' => $ok,
            'reason' => $reason,
            'detail' => $detail,
            'php_binary' => $phpBinary,
            'node_binary_forwarded' => $nodeBinary,
            'launched_command' => $launchedCommand,
            'spawn_exit_code' => $spawnExitCode,
            'sapi' => PHP_SAPI,
        ];
        if ($resolutionMeta !== null) {
            $payload['resolution'] = $resolutionMeta;
        }
        self::writeSpawnLogFile($payload);
    }

    private static function resolveCmdExe(): string
    {
        $comSpec = getenv('ComSpec');
        if (is_string($comSpec) && $comSpec !== '' && is_file($comSpec)) {
            return $comSpec;
        }

        return 'C:\Windows\System32\cmd.exe';
    }

    private static function quoteWinArg(string $arg): string
    {
        return '"' . str_replace('"', '""', $arg) . '"';
    }

    private static function quoteWinPsSingle(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
