<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\App\ClientIp;
use Core\App\Database;

/**
 * Two-layer login throttle: Layer A = email + IP (progressive rolling cooldowns), Layer B = email-only abuse guard (high threshold).
 */
final class LoginThrottleService
{
    private const PREFIX_A = 'A1:';
    private const PREFIX_B = 'B1:';

    /**
     * Hard ceiling for any returned lockout (matches max tier cooldown). Prevents absurd waits when
     * lastFailureUnixInWindow() would otherwise see a future created_at (clock skew / bad rows).
     */
    public const MAX_REMAINING_LOCKOUT_SEC = 900;

    /** Ignore created_at rows more than this many seconds in the future (DB vs PHP clock skew). */
    private const CREATED_AT_FUTURE_SKEW_SEC = 120;

    /** Layer A: fails in 5 min → 30 s pause after last failure */
    private const A1_WINDOW_SEC = 300;
    private const A1_MIN_FAILS = 5;
    private const A1_COOLDOWN_SEC = 30;

    /** Layer A: fails in 10 min → 120 s pause */
    private const A2_WINDOW_SEC = 600;
    private const A2_MIN_FAILS = 7;
    private const A2_COOLDOWN_SEC = 120;

    /** Layer A: fails in 15 min → 900 s pause */
    private const A3_WINDOW_SEC = 900;
    private const A3_MIN_FAILS = 10;
    private const A3_COOLDOWN_SEC = 900;

    /** Layer B: many failures across IPs in 30 min → 900 s (email-scoped) */
    private const B_WINDOW_SEC = 1800;
    private const B_MIN_FAILS = 45;
    private const B_COOLDOWN_SEC = 900;

    public function __construct(private Database $db)
    {
    }

    public function isLockedOut(string $emailNormalized): bool
    {
        return $this->remainingLockoutSeconds($emailNormalized) > 0;
    }

    /**
     * Cooldown seconds before another login attempt is allowed for this email from the current client IP.
     */
    public function remainingLockoutSeconds(string $emailNormalized): int
    {
        $emailNormalized = strtolower(trim($emailNormalized));
        $ip = $this->clientIp();
        $aKey = $this->layerAKey($emailNormalized, $ip);
        $bKey = $this->layerBKey($emailNormalized);

        $raw = max(
            $this->layerARemainingSeconds($aKey),
            $this->layerBRemainingSeconds($bKey)
        );

        return min($raw, self::MAX_REMAINING_LOCKOUT_SEC);
    }

    public function recordAttempt(string $emailNormalized, bool $success): void
    {
        $emailNormalized = strtolower(trim($emailNormalized));
        $ip = $this->clientIp();
        $aKey = $this->layerAKey($emailNormalized, $ip);

        if ($success) {
            $this->db->insert('login_attempts', [
                'identifier' => $aKey,
                'success' => 1,
                'ip_address' => $ip !== '' ? $ip : null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);

            return;
        }

        $this->db->insert('login_attempts', [
            'identifier' => $aKey,
            'success' => 0,
            'ip_address' => $ip !== '' ? $ip : null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        $bKey = $this->layerBKey($emailNormalized);
        $this->db->insert('login_attempts', [
            'identifier' => $bKey,
            'success' => 0,
            'ip_address' => $ip !== '' ? $ip : null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function prune(): void
    {
        $older = date('Y-m-d H:i:s', strtotime('-7 days'));
        $this->db->query('DELETE FROM login_attempts WHERE created_at < ?', [$older]);
    }

    /**
     * Clears failure state for this email + current IP (Layer A) and global email bucket (Layer B), plus legacy rows keyed by raw email.
     */
    public function clearFailures(string $emailNormalized): void
    {
        $emailNormalized = strtolower(trim($emailNormalized));
        $ip = $this->clientIp();
        $aKey = $this->layerAKey($emailNormalized, $ip);
        $bKey = $this->layerBKey($emailNormalized);
        $this->db->query(
            'DELETE FROM login_attempts WHERE success = 0 AND (identifier = ? OR identifier = ? OR identifier = ?)',
            [$aKey, $bKey, $emailNormalized]
        );
    }

    /** @see ClientIp::forRequest() — empty string preserved when resolver has no peer (matches legacy REMOTE_ADDR-missing keys). */
    private function clientIp(): string
    {
        $ip = ClientIp::forRequest();
        if ($ip === '0.0.0.0') {
            return '';
        }

        return $ip;
    }

    /**
     * Stable Layer A identifier (email+IP) for tooling — must match {@see recordAttempt()} / {@see clearFailures()}.
     */
    public static function canonicalLayerAKey(string $emailNormalized, string $ip): string
    {
        $emailNormalized = strtolower(trim($emailNormalized));
        $ip = trim($ip);

        return self::PREFIX_A . hash('sha256', $emailNormalized . "\xff" . $ip);
    }

    /**
     * Stable Layer B identifier (email-only) for tooling.
     */
    public static function canonicalLayerBKey(string $emailNormalized): string
    {
        $emailNormalized = strtolower(trim($emailNormalized));

        return self::PREFIX_B . hash('sha256', $emailNormalized);
    }

    private function layerAKey(string $emailNormalized, string $ip): string
    {
        return self::canonicalLayerAKey($emailNormalized, $ip);
    }

    private function layerBKey(string $emailNormalized): string
    {
        return self::canonicalLayerBKey($emailNormalized);
    }

    /**
     * Rolling window in SQL uses MySQL NOW() so bounds match CURRENT_TIMESTAMP on inserts
     * (avoids PHP vs DB clock drift excluding real rows). Upper bound drops only pathological future rows.
     */
    private function countFailuresSince(string $identifier, int $windowSec): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = ? AND success = 0
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
             AND created_at <= DATE_ADD(NOW(), INTERVAL ? SECOND)',
            [$identifier, $windowSec, self::CREATED_AT_FUTURE_SKEW_SEC]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function lastFailureUnixInWindow(string $identifier, int $windowSec): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT UNIX_TIMESTAMP(created_at) AS ts FROM login_attempts WHERE identifier = ? AND success = 0
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
             AND created_at <= DATE_ADD(NOW(), INTERVAL ? SECOND)
             ORDER BY created_at DESC LIMIT 1',
            [$identifier, $windowSec, self::CREATED_AT_FUTURE_SKEW_SEC]
        );
        if ($row === null || !isset($row['ts']) || $row['ts'] === null || $row['ts'] === '') {
            return null;
        }
        $t = (int) $row['ts'];
        if ($t <= 0) {
            return null;
        }
        $now = time();
        if ($t > $now + self::CREATED_AT_FUTURE_SKEW_SEC) {
            return $now;
        }

        return $t;
    }

    private function layerARemainingSeconds(string $aKey): int
    {
        $c5 = $this->countFailuresSince($aKey, self::A1_WINDOW_SEC);
        $c10 = $this->countFailuresSince($aKey, self::A2_WINDOW_SEC);
        $c15 = $this->countFailuresSince($aKey, self::A3_WINDOW_SEC);

        $cooldown = 0;
        if ($c5 >= self::A1_MIN_FAILS) {
            $cooldown = max($cooldown, self::A1_COOLDOWN_SEC);
        }
        if ($c10 >= self::A2_MIN_FAILS) {
            $cooldown = max($cooldown, self::A2_COOLDOWN_SEC);
        }
        if ($c15 >= self::A3_MIN_FAILS) {
            $cooldown = max($cooldown, self::A3_COOLDOWN_SEC);
        }
        if ($cooldown === 0) {
            return 0;
        }

        $tLast = $this->lastFailureUnixInWindow($aKey, self::A3_WINDOW_SEC);
        if ($tLast === null) {
            return 0;
        }

        $remaining = max(0, $cooldown - (time() - $tLast));

        return min($remaining, self::MAX_REMAINING_LOCKOUT_SEC);
    }

    private function layerBRemainingSeconds(string $bKey): int
    {
        $cb = $this->countFailuresSince($bKey, self::B_WINDOW_SEC);
        if ($cb < self::B_MIN_FAILS) {
            return 0;
        }
        $tLast = $this->lastFailureUnixInWindow($bKey, self::B_WINDOW_SEC);
        if ($tLast === null) {
            return 0;
        }

        $remaining = max(0, self::B_COOLDOWN_SEC - (time() - $tLast));

        return min($remaining, self::MAX_REMAINING_LOCKOUT_SEC);
    }
}
