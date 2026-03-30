<?php

declare(strict_types=1);

namespace Core\App;

/**
 * Canonical client IP for throttling, audit, and other security-relevant server-side decisions (use {@see forRequest()}).
 * When `app.trusted_proxies` is empty (default), only `REMOTE_ADDR` is used — safe behind no proxy.
 * When the immediate peer (`REMOTE_ADDR`) is listed as trusted, the first valid IP from
 * `X-Forwarded-For` (or `X-Real-IP`) is used.
 */
final class ClientIp
{
    public static function forRequest(): string
    {
        return self::fromServerArray($_SERVER);
    }

    /**
     * @param array<string, mixed> $server Typically $_SERVER
     */
    public static function fromServerArray(array $server): string
    {
        $direct = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($direct === '') {
            return '0.0.0.0';
        }

        $trusted = self::trustedProxyList();
        if ($trusted === [] || !self::addressMatchesTrustedList($direct, $trusted)) {
            return $direct;
        }

        $xff = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            foreach (array_map('trim', explode(',', $xff)) as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                if (str_starts_with($candidate, '[') && ($end = strpos($candidate, ']')) !== false) {
                    $candidate = substr($candidate, 1, $end - 1);
                }
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false) {
                    return $candidate;
                }
                if (preg_match('/^([\d.]+):\d+$/', $candidate, $m)
                    && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return $m[1];
                }
            }
        }

        $real = trim((string) ($server['HTTP_X_REAL_IP'] ?? ''));
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }

        return $direct;
    }

    /** @return list<string> */
    private static function trustedProxyList(): array
    {
        $raw = Application::config('app.trusted_proxies', []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $ip) {
            if (is_string($ip) && $ip !== '') {
                $out[] = $ip;
            }
        }

        return $out;
    }

    /** @param list<string> $trusted */
    private static function addressMatchesTrustedList(string $remoteAddr, array $trusted): bool
    {
        foreach ($trusted as $t) {
            if ($remoteAddr === $t) {
                return true;
            }
        }

        return false;
    }
}
