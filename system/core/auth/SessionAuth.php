<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\App\Database;
use Core\Runtime\Session\SessionBackendConfigurator;

final class SessionAuth
{
    private const SESSION_KEY = 'user_id';
    private const CSRF_KEY = 'csrf_token';
    /** Platform founder’s real user id while the effective session user is a tenant (support entry). */
    private const SUPPORT_ACTOR_USER_ID = '_support_actor_user_id';
    /** Short label for UI (e.g. founder email). */
    private const SUPPORT_ACTOR_LABEL = '_support_actor_label';
    /** Pairs founder_support_session_start / founder_support_session_end audit rows. */
    private const SUPPORT_SESSION_CORRELATION_ID = '_support_session_correlation_id';
    /** Unix timestamp; updated on each authenticated request (see AuthMiddleware). */
    private const LAST_ACTIVITY_AT = '_last_activity_at';
    /** Session flag: audit log for password-expiration block was recorded (avoid spam per session). */
    public const SESSION_PASSWORD_EXPIRY_BLOCK_AUDIT = '_password_expiry_block_audit_logged';

    /**
     * Mirrors {@see UserSessionEpochRepository} `users.session_version` at login/support transitions.
     * When DB version increases, {@see SessionEpochCoordinator} invalidates the session (logout-all / revoke).
     */
    public const SESSION_EPOCH_KEY = '_session_epoch';

    /**
     * Request-scope memoization for {@see user()}: last session user id for which {@see $requestScopedUserRow} was loaded this request.
     */
    private ?int $requestScopedUserResolvedForId = null;

    /** @var array<string, mixed>|null Cached users row (null if id had no live row when loaded). */
    private ?array $requestScopedUserRow = null;

    public function __construct(
        private Database $db,
        private UserSessionEpochRepository $sessionEpochs,
    ) {
        $this->startSession();
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $this->configureSession();
            session_start();
        }
    }

    /**
     * Closes session persistence early for this request (releases write lock). {@code $_SESSION} remains readable in-memory.
     *
     * Do not use after this point if anything still needs to persist session mutations (CSRF mint, flash, login, support entry).
     */
    public function releaseWriteLockEarly(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function configureSession(): void
    {
        // Apply invariants before session open (PHP default cookie name / lax defaults must not win).
        ini_set('session.use_strict_mode', '1');
        $cfg = config('session') ?? [];
        if (is_array($cfg)) {
            SessionBackendConfigurator::apply($cfg);
        }
        session_name($cfg['cookie_name'] ?? 'spa_session');
        session_set_cookie_params([
            'lifetime' => ($cfg['lifetime'] ?? 120) * 60,
            'path' => $cfg['path'] ?? '/',
            'domain' => $cfg['domain'] ?? '',
            'secure' => $cfg['secure'] ?? true,
            'httponly' => $cfg['httponly'] ?? true,
            'samesite' => $cfg['samesite'] ?? 'Lax',
        ]);
    }

    public function login(int $userId): void
    {
        $this->requestScopedUserResolvedForId = null;
        $this->requestScopedUserRow = null;
        $this->regenerateSession();
        $this->clearSupportEntryKeys();
        $_SESSION[self::SESSION_KEY] = $userId;
        $_SESSION[self::SESSION_EPOCH_KEY] = $this->sessionEpochs->getSessionVersion($userId);
        $_SESSION[self::LAST_ACTIVITY_AT] = time();
    }

    public function logout(): void
    {
        $this->requestScopedUserResolvedForId = null;
        $this->requestScopedUserRow = null;
        $id = session_id();
        if ($id) {
            $_SESSION = [];
            $cfg = config('session') ?? [];
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 86400,
                    'path' => $cfg['path'] ?? '/',
                    'domain' => $cfg['domain'] ?? '',
                    'secure' => $cfg['secure'] ?? false,
                    'httponly' => $cfg['httponly'] ?? true,
                    'samesite' => $cfg['samesite'] ?? 'Lax',
                ]
            );
            session_destroy();
        }
    }

    public function id(): ?int
    {
        return isset($_SESSION[self::SESSION_KEY]) ? (int) $_SESSION[self::SESSION_KEY] : null;
    }

    public function isSupportEntryActive(): bool
    {
        return isset($_SESSION[self::SUPPORT_ACTOR_USER_ID]) && (int) $_SESSION[self::SUPPORT_ACTOR_USER_ID] > 0;
    }

    /**
     * When {@see isSupportEntryActive()}, the platform principal performing support; otherwise null.
     */
    public function supportActorUserId(): ?int
    {
        if (!$this->isSupportEntryActive()) {
            return null;
        }

        return (int) $_SESSION[self::SUPPORT_ACTOR_USER_ID];
    }

    /**
     * Human-readable founder label stored at support-entry start (e.g. email).
     */
    public function supportActorLabel(): ?string
    {
        if (!$this->isSupportEntryActive()) {
            return null;
        }
        $l = $_SESSION[self::SUPPORT_ACTOR_LABEL] ?? null;

        return is_string($l) && $l !== '' ? $l : null;
    }

    public function supportSessionCorrelationId(): ?string
    {
        if (!$this->isSupportEntryActive()) {
            return null;
        }
        $c = $_SESSION[self::SUPPORT_SESSION_CORRELATION_ID] ?? null;

        return is_string($c) && $c !== '' ? $c : null;
    }

    /**
     * User id to record as AuditService actor: real founder during support entry, else session user.
     *
     * @return int|null null when unauthenticated
     */
    public function auditActorUserId(): ?int
    {
        $sid = $this->id();
        if ($sid === null || $sid <= 0) {
            return null;
        }
        $actor = $this->supportActorUserId();

        return $actor !== null && $actor > 0 ? $actor : $sid;
    }

    /**
     * @param non-empty-string $correlationId
     * @param non-empty-string $founderDisplayLabel
     */
    public function beginSupportEntry(int $founderUserId, string $founderDisplayLabel, int $tenantUserId, string $correlationId, ?int $branchSessionId): void
    {
        if ($this->isSupportEntryActive()) {
            throw new \RuntimeException('A support entry session is already active.');
        }
        if ($founderUserId <= 0 || $tenantUserId <= 0) {
            throw new \InvalidArgumentException('Invalid user id for support entry.');
        }
        $this->regenerateToken();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SUPPORT_ACTOR_USER_ID] = $founderUserId;
        $_SESSION[self::SUPPORT_ACTOR_LABEL] = $founderDisplayLabel;
        $_SESSION[self::SUPPORT_SESSION_CORRELATION_ID] = $correlationId;
        $_SESSION[self::SESSION_KEY] = $tenantUserId;
        $_SESSION[self::SESSION_EPOCH_KEY] = $this->sessionEpochs->getSessionVersion($tenantUserId);
        $_SESSION[self::LAST_ACTIVITY_AT] = time();
        if ($branchSessionId !== null && $branchSessionId > 0) {
            $_SESSION['branch_id'] = $branchSessionId;
        } else {
            unset($_SESSION['branch_id']);
        }
        $this->requestScopedUserResolvedForId = null;
        $this->requestScopedUserRow = null;
    }

    public function endSupportEntry(): void
    {
        if (!$this->isSupportEntryActive()) {
            return;
        }
        $founderId = (int) $_SESSION[self::SUPPORT_ACTOR_USER_ID];
        $this->clearSupportEntryKeys();
        $_SESSION[self::SESSION_KEY] = $founderId;
        $_SESSION[self::SESSION_EPOCH_KEY] = $this->sessionEpochs->getSessionVersion($founderId);
        unset($_SESSION['branch_id']);
        $this->regenerateToken();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::LAST_ACTIVITY_AT] = time();
        $this->requestScopedUserResolvedForId = null;
        $this->requestScopedUserRow = null;
    }

    private function clearSupportEntryKeys(): void
    {
        unset(
            $_SESSION[self::SUPPORT_ACTOR_USER_ID],
            $_SESSION[self::SUPPORT_ACTOR_LABEL],
            $_SESSION[self::SUPPORT_SESSION_CORRELATION_ID]
        );
    }

    public function user(): ?array
    {
        $id = $this->id();
        if (!$id) {
            $this->requestScopedUserResolvedForId = null;
            $this->requestScopedUserRow = null;

            return null;
        }
        if ($this->requestScopedUserResolvedForId === $id) {
            return $this->requestScopedUserRow;
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT id, email, name, branch_id, password_changed_at, created_at FROM users WHERE id = ? AND deleted_at IS NULL',
                [$id]
            );
        } catch (\Throwable $e) {
            // Migration 055 (users.password_changed_at) not applied: avoid PDO "Unknown column" on every authenticated request.
            if (!str_contains($e->getMessage(), 'password_changed_at')) {
                throw $e;
            }

            $row = $this->db->fetchOne(
                'SELECT id, email, name, branch_id, created_at FROM users WHERE id = ? AND deleted_at IS NULL',
                [$id]
            );
        }
        $this->requestScopedUserResolvedForId = $id;
        $this->requestScopedUserRow = $row;

        return $row;
    }

    public function validateCsrf(string $token): bool
    {
        return $token !== '' && hash_equals($_SESSION[self::CSRF_KEY] ?? '', $token);
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    private function regenerateToken(): void
    {
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
    }

    private function regenerateSession(): void
    {
        $this->regenerateToken();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * For authenticated admin requests: if idle longer than configured minutes, return false (caller should logout).
     * Otherwise refresh the activity timestamp and return true.
     */
    public function touchActivityWithinInactivityLimit(int $timeoutMinutes): bool
    {
        if ($this->id() === null) {
            return true;
        }
        $timeoutMinutes = max(1, $timeoutMinutes);
        $limit = $timeoutMinutes * 60;
        $now = time();
        $last = $_SESSION[self::LAST_ACTIVITY_AT] ?? null;
        if ($last === null || !is_numeric($last)) {
            $_SESSION[self::LAST_ACTIVITY_AT] = $now;
            return true;
        }
        $last = (int) $last;
        if ($now - $last > $limit) {
            return false;
        }
        $_SESSION[self::LAST_ACTIVITY_AT] = $now;
        return true;
    }
}
