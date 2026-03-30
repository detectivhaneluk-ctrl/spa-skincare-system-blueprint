<?php

declare(strict_types=1);

namespace Modules\Media\Services;

/**
 * Read-only observability for local image pipeline (Node worker + CLI PHP drain).
 * Used by HTTP status JSON, dev scripts, and diagnostics — no side effects.
 */
final class MediaWorkerLocalRuntimeProbe
{
    /**
     * @return array{path:?string,source:string,detail:string}
     */
    public static function resolveCliPhpBinaryDetailed(): array
    {
        $explicit = env('MEDIA_DEV_PHP_BINARY', null);
        if (is_string($explicit) && $explicit !== '') {
            if (self::isPlausiblePhpExecutable($explicit)) {
                return ['path' => $explicit, 'source' => 'MEDIA_DEV_PHP_BINARY', 'detail' => 'explicit env path'];
            }

            return ['path' => null, 'source' => 'MEDIA_DEV_PHP_BINARY', 'detail' => 'configured path is not a valid php.exe'];
        }

        $candidate = PHP_BINARY;
        if (PHP_OS_FAMILY === 'Windows' && $candidate !== '' && preg_match('/php-cgi\.exe$/i', $candidate)) {
            $sibling = dirname($candidate) . DIRECTORY_SEPARATOR . 'php.exe';
            if (self::isPlausiblePhpExecutable($sibling)) {
                return ['path' => $sibling, 'source' => 'php_cgi_sibling_php', 'detail' => 'resolved php.exe next to php-cgi.exe'];
            }
        }

        if (PHP_SAPI === 'cli' && $candidate !== '' && self::isPlausiblePhpExecutable($candidate)) {
            return ['path' => $candidate, 'source' => 'PHP_BINARY_cli', 'detail' => 'current CLI binary'];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $pathPhp = self::resolvePhpFromPath();
            if ($pathPhp !== null) {
                return ['path' => $pathPhp, 'source' => 'PATH', 'detail' => 'resolved php executable from PATH'];
            }

            $laragon = self::discoverLaragonPhpExecutable();
            if ($laragon !== null) {
                return ['path' => $laragon, 'source' => 'laragon_discovery', 'detail' => 'discovered from Laragon install tree'];
            }
        }

        $pathPhp = self::resolvePhpFromPath();
        if ($pathPhp !== null) {
            return ['path' => $pathPhp, 'source' => 'PATH', 'detail' => 'resolved php executable from PATH'];
        }

        return ['path' => null, 'source' => 'none', 'detail' => 'could not resolve CLI php'];
    }

    /**
     * @return 'yes'|'no'|'unknown'
     */
    public static function probeNodeImageWorkerProcess(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $out = shell_exec('powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name = \'node.exe\'\" | ForEach-Object { $_.CommandLine }" 2>nul');
            if ($out === null || trim($out) === '') {
                return 'unknown';
            }
            $lines = preg_split('/\r?\n/', trim($out));
            foreach ($lines as $l) {
                if ($l === '') {
                    continue;
                }
                if (str_contains($l, 'worker.mjs') && str_contains($l, 'image-pipeline')) {
                    return 'yes';
                }
            }

            return 'no';
        }

        $ps = shell_exec('ps aux 2>/dev/null');
        if ($ps !== null && $ps !== '') {
            foreach (preg_split('/\r?\n/', $ps) as $line) {
                if ($line !== '' && str_contains($line, 'node') && str_contains($line, 'worker.mjs') && str_contains($line, 'image-pipeline')) {
                    return 'yes';
                }
            }
        }

        return 'no';
    }

    /**
     * Resolves a CLI-capable PHP binary for spawning drain scripts from the web SAPI.
     * Priority: MEDIA_DEV_PHP_BINARY → CLI SAPI PHP_BINARY → web SAPI sibling php.exe (php-cgi → php.exe).
     */
    public static function resolveCliPhpBinary(): ?string
    {
        $d = self::resolveCliPhpBinaryDetailed();

        return is_string($d['path'] ?? null) && $d['path'] !== '' ? $d['path'] : null;
    }

    /**
     * @return array{path:?string,source:string,detail:string}
     */
    public static function resolveNodeBinaryDetailed(): array
    {
        $cfg = self::resolveConfiguredNodeBinary();
        if ($cfg !== null) {
            return ['path' => $cfg, 'source' => 'NODE_BINARY', 'detail' => 'explicit env path'];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $fromWhere = self::resolveNodeFromWhere();
            if ($fromWhere !== null) {
                return ['path' => $fromWhere, 'source' => 'where_node', 'detail' => 'resolved by where node'];
            }

            $common = [
                'C:\Program Files\nodejs\node.exe',
                'C:\Program Files (x86)\nodejs\node.exe',
                'C:\laragon\bin\nodejs\node-v*\node.exe',
                'C:\laragon\bin\nodejs\node.exe',
            ];
            foreach ($common as $pattern) {
                $matches = glob($pattern);
                if (!is_array($matches) || $matches === []) {
                    continue;
                }
                rsort($matches, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($matches as $m) {
                    if (is_file($m)) {
                        return ['path' => $m, 'source' => 'common_windows_path', 'detail' => 'discovered from common Windows install paths'];
                    }
                }
            }
        }

        $which = shell_exec('command -v node 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            $line = trim(strtok(str_replace("\r", '', $which), "\n"));
            if ($line !== '') {
                return ['path' => $line, 'source' => 'PATH', 'detail' => 'resolved node from PATH'];
            }
        }

        return ['path' => null, 'source' => 'none', 'detail' => 'could not resolve node binary'];
    }

    public static function resolveNodeBinary(): ?string
    {
        $d = self::resolveNodeBinaryDetailed();

        return is_string($d['path'] ?? null) && $d['path'] !== '' ? $d['path'] : null;
    }

    /**
     * Path to node if explicitly set and valid; otherwise null (caller may fall back to PATH at runtime).
     */
    public static function resolveConfiguredNodeBinary(): ?string
    {
        $nb = env('NODE_BINARY', null);
        if (!is_string($nb) || $nb === '') {
            return null;
        }

        return is_file($nb) ? $nb : null;
    }

    /**
     * Whether `node` is likely invokable (explicit file exists, or `node` / `where node` on Windows).
     */
    public static function isNodeBinaryResolvableForDiagnostics(): bool
    {
        return self::resolveNodeBinary() !== null;
    }

    private static function discoverLaragonPhpExecutable(): ?string
    {
        $patterns = [
            'C:\laragon\bin\php\php*\php.exe',
            'C:\laragon\bin\php\php.exe',
        ];
        $preferredPrefix = 'php-' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if (!is_array($matches) || $matches === []) {
                continue;
            }
            rsort($matches, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($matches as $m) {
                $base = strtolower((string) basename((string) dirname($m)));
                if (!str_starts_with($base, strtolower($preferredPrefix))) {
                    continue;
                }
                if (self::isPlausiblePhpExecutable($m)) {
                    return $m;
                }
            }
            foreach ($matches as $m) {
                if (self::isPlausiblePhpExecutable($m)) {
                    return $m;
                }
            }
        }

        return null;
    }

    private static function resolvePhpFromPath(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $out = shell_exec('where php 2>nul');
            if (!is_string($out) || trim($out) === '') {
                $out = shell_exec('where.exe php 2>nul');
            }
            if (!is_string($out) || trim($out) === '') {
                return null;
            }
            foreach (preg_split('/\r?\n/', trim($out)) as $line) {
                $candidate = trim($line);
                if ($candidate !== '' && self::isPlausiblePhpExecutable($candidate)) {
                    return $candidate;
                }
            }

            return null;
        }

        $which = shell_exec('command -v php 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            return null;
        }
        $line = trim(strtok(str_replace("\r", '', $which), "\n"));

        return $line !== '' && self::isPlausiblePhpExecutable($line) ? $line : null;
    }

    private static function resolveNodeFromWhere(): ?string
    {
        $out = shell_exec('where node 2>nul');
        if (!is_string($out) || trim($out) === '') {
            $out = shell_exec('where.exe node 2>nul');
        }
        if (!is_string($out) || trim($out) === '') {
            return null;
        }
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            $candidate = trim($line);
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function isPlausiblePhpExecutable(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return (bool) preg_match('/php(?:-cli)?\.exe$/i', $path);
        }

        return is_executable($path);
    }
}
