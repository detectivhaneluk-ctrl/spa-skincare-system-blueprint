<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Normalizes user-supplied post-action redirect targets to same-origin relative URLs only.
 * Rejects scheme-relative URLs ({@code //host}), absolute URLs, backslash paths, and other external/malformed forms.
 */
final class SafeInternalRedirectPath
{
    public const DEFAULT_PATH = '/dashboard';

    private const MAX_INPUT_BYTES = 2048;

    private const MAX_DECODE_ROUNDS = 8;

    /**
     * @param mixed $raw Typically {@code $_POST['redirect_to']}
     */
    public static function normalize(mixed $raw): string
    {
        if (!is_string($raw)) {
            return self::DEFAULT_PATH;
        }
        $s = trim($raw);
        if ($s === '' || strlen($s) > self::MAX_INPUT_BYTES) {
            return self::DEFAULT_PATH;
        }
        $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? '';
        if ($s === '' || str_contains($s, '\\')) {
            return self::DEFAULT_PATH;
        }

        if (!str_starts_with($s, '/') || str_starts_with($s, '//')) {
            return self::DEFAULT_PATH;
        }

        $parsed = parse_url($s);
        if ($parsed === false) {
            return self::DEFAULT_PATH;
        }
        if (isset($parsed['scheme']) || isset($parsed['host']) || isset($parsed['port'])) {
            return self::DEFAULT_PATH;
        }

        $path = $parsed['path'] ?? '';
        if ($path === '' || $path[0] !== '/') {
            return self::DEFAULT_PATH;
        }

        $path = self::iterativeDecode($path);
        $path = trim($path);
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path) ?? '';
        if ($path === '' || str_contains($path, '\\')) {
            return self::DEFAULT_PATH;
        }
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return self::DEFAULT_PATH;
        }
        if (strlen($path) > 1 && str_contains(substr($path, 1), '//')) {
            return self::DEFAULT_PATH;
        }

        $out = $path;
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $out .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment']) && $parsed['fragment'] !== '') {
            $out .= '#' . $parsed['fragment'];
        }

        if ($out === '' || str_starts_with($out, '//')) {
            return self::DEFAULT_PATH;
        }

        return $out;
    }

    private static function iterativeDecode(string $path): string
    {
        $prev = '';
        $s = $path;
        for ($i = 0; $i < self::MAX_DECODE_ROUNDS && $s !== $prev; $i++) {
            $prev = $s;
            $s = rawurldecode($s);
        }

        return $s;
    }
}
