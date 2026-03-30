<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;

final class AuthService
{
    public function __construct(
        private SessionAuth $session,
        private LoginThrottleService $throttle,
        private SessionEpochCoordinator $sessionEpochCoordinator,
        private UserSessionEpochRepository $sessionEpochs,
    ) {
    }

    public function check(): bool
    {
        if ($this->session->id() === null) {
            return false;
        }
        if (!$this->sessionEpochCoordinator->assertAuthenticatedSessionEpochValid()) {
            $this->session->logout();

            return false;
        }
        if ($this->session->user() === null) {
            $this->session->logout();
            return false;
        }
        return true;
    }

    public function user(): ?array
    {
        return $this->session->user();
    }

    public function attempt(string $email, string $password): bool
    {
        $identifier = strtolower($email);
        if ($this->throttle->isLockedOut($identifier)) {
            return false;
        }

        $user = $this->db()->fetchOne(
            'SELECT id, password_hash FROM users WHERE email = ? AND deleted_at IS NULL',
            [$identifier]
        );
        $verified = $user && password_verify($password, $user['password_hash']);
        $this->throttle->recordAttempt($identifier, $verified);

        if (!$verified) {
            return false;
        }
        $this->throttle->clearFailures($identifier);
        $this->throttle->prune();
        $this->session->login((int) $user['id']);
        return true;
    }

    public function remainingLockoutSeconds(string $identifier): int
    {
        return $this->throttle->remainingLockoutSeconds(strtolower($identifier));
    }

    public function logout(): void
    {
        $this->session->logout();
    }

    /**
     * Change password for the currently authenticated user. Updates password_changed_at when that column exists (migration 055).
     *
     * @throws \InvalidArgumentException when validation fails or current password is wrong
     */
    public function updatePasswordForCurrentUser(string $currentPassword, string $newPassword): void
    {
        $userId = $this->session->id();
        if ($userId === null) {
            throw new \InvalidArgumentException('Not authenticated.');
        }
        $this->assertAccountPasswordChangeAllowed($userId);
        $newPassword = trim($newPassword);
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('New password must be at least 8 characters.');
        }
        $row = $this->db()->fetchOne(
            'SELECT id, password_hash FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId]
        );
        if ($row === null) {
            throw new \InvalidArgumentException('User not found.');
        }
        $throttleId = self::accountPasswordChangeThrottleId($userId);
        if (!password_verify($currentPassword, (string) $row['password_hash'])) {
            $this->throttle->recordAttempt($throttleId, false);
            throw new \InvalidArgumentException('Current password is incorrect.');
        }
        $this->throttle->clearFailures($throttleId);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        try {
            $this->db()->query(
                'UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?',
                [$hash, $userId]
            );
        } catch (\Throwable $e) {
            // Migration 055 (users.password_changed_at) not applied: update hash only (same idea as SessionAuth::user()).
            if (!str_contains($e->getMessage(), 'password_changed_at')) {
                throw $e;
            }
            $this->db()->query(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [$hash, $userId]
            );
        }
        $this->sessionEpochs->incrementSessionVersion($userId);
        $_SESSION[SessionAuth::SESSION_EPOCH_KEY] = $this->sessionEpochs->getSessionVersion($userId);
        Application::container()->get(AuditService::class)->log('password_changed', 'user', $userId, $userId, null, null, 'success', 'auth');
    }

    /**
     * Throttle bucket for POST /account/password (wrong current-password attempts); separate from login and platform step-up keys.
     */
    public static function accountPasswordChangeThrottleId(int $userId): string
    {
        return 'account_password_change:' . $userId;
    }

    /**
     * @throws \InvalidArgumentException when temporarily locked out after failed current-password checks
     */
    public function assertAccountPasswordChangeAllowed(int $userId): void
    {
        $id = self::accountPasswordChangeThrottleId($userId);
        $rem = $this->throttle->remainingLockoutSeconds($id);
        if ($rem > 0) {
            throw new \InvalidArgumentException(
                'Too many password change attempts. Try again in ' . $rem . ' seconds.'
            );
        }
    }

    /**
     * Stable throttle bucket for support-entry password step-up (not the user's email — avoids conflating with login abuse signals).
     */
    public static function supportEntryStepUpThrottleId(int $userId): string
    {
        return 'support_entry_stepup:' . $userId;
    }

    /**
     * Fail-closed gate before accepting a support-entry password attempt (separate message from wrong-password).
     *
     * @throws \InvalidArgumentException when temporarily locked out after failed attempts
     */
    public function assertSupportEntryPasswordStepUpAllowed(int $userId): void
    {
        $id = self::supportEntryStepUpThrottleId($userId);
        $rem = $this->throttle->remainingLockoutSeconds($id);
        if ($rem > 0) {
            throw new \InvalidArgumentException(
                'Too many password confirmation attempts. Try again in ' . $rem . ' seconds.'
            );
        }
    }

    /**
     * Verify the account password for step-up (no session mutation). Reuses login_attempts throttle keyed by {@see supportEntryStepUpThrottleId()}.
     * Call {@see assertSupportEntryPasswordStepUpAllowed()} first for a clear lockout error.
     *
     * Extension seam: a future MFA verifier can be invoked from the same guardrail instead of or in addition to this check.
     */
    public function verifyPasswordForUserStepUp(int $userId, string $plainPassword): bool
    {
        $id = self::supportEntryStepUpThrottleId($userId);
        $row = $this->db()->fetchOne(
            'SELECT id, password_hash FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId]
        );
        if ($row === null) {
            $this->throttle->recordAttempt($id, false);

            return false;
        }
        $ok = password_verify($plainPassword, (string) $row['password_hash']);
        $this->throttle->recordAttempt($id, $ok);
        if ($ok) {
            $this->throttle->clearFailures($id);
        }

        return $ok;
    }

    /**
     * Throttle bucket for platform.manage high-impact mutations (separate from support-entry step-up).
     */
    public static function platformManageStepUpThrottleId(int $userId): string
    {
        return 'platform_manage_stepup:' . $userId;
    }

    /**
     * @throws \InvalidArgumentException when temporarily locked out after failed attempts
     */
    public function assertPlatformManagePasswordStepUpAllowed(int $userId): void
    {
        $id = self::platformManageStepUpThrottleId($userId);
        $rem = $this->throttle->remainingLockoutSeconds($id);
        if ($rem > 0) {
            throw new \InvalidArgumentException(
                'Too many password confirmation attempts. Try again in ' . $rem . ' seconds.'
            );
        }
    }

    /**
     * Knowledge-factor re-auth for platform manage POST mutations (no session mutation).
     * Call {@see assertPlatformManagePasswordStepUpAllowed()} first for a clear lockout error.
     */
    public function verifyPasswordForPlatformManageStepUp(int $userId, string $plainPassword): bool
    {
        $id = self::platformManageStepUpThrottleId($userId);
        $row = $this->db()->fetchOne(
            'SELECT id, password_hash FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId]
        );
        if ($row === null) {
            $this->throttle->recordAttempt($id, false);

            return false;
        }
        $ok = password_verify($plainPassword, (string) $row['password_hash']);
        $this->throttle->recordAttempt($id, $ok);
        if ($ok) {
            $this->throttle->clearFailures($id);
        }

        return $ok;
    }

    private function db(): Database
    {
        return Application::container()->get(\Core\App\Database::class);
    }
}
