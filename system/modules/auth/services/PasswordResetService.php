<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Core\App\Database;
use Core\App\Application;
use Core\Auth\PasswordResetRequestLogRepository;
use Core\Auth\UserPasswordResetTokenRepository;
use Core\Audit\AuditService;
use Modules\Notifications\Repositories\OutboundNotificationMessageRepository;
use PDOException;

/**
 * Unauthenticated staff password reset: token issuance (hashed at rest), expiry, single-use consume,
 * outbound queue row for email delivery only (`OutboundChannelPolicy`; existing dispatch pipeline — not inbox-guaranteed).
 */
final class PasswordResetService
{
    public const EVENT_KEY = 'auth.staff_password_reset';

    private const TOKEN_TTL_SECONDS = 3600;
    private const MAX_REQUESTS_PER_EMAIL_PER_HOUR = 5;
    private const MAX_REQUESTS_PER_IP_PER_HOUR = 25;

    public function __construct(
        private Database $db,
        private UserPasswordResetTokenRepository $tokens,
        private PasswordResetRequestLogRepository $requestLog,
        private OutboundNotificationMessageRepository $outboundMessages,
        private AuditService $audit
    ) {
    }

    /**
     * Always treat as success at the HTTP layer (enumeration-safe). Creates token + outbound row only when user exists and limits allow.
     */
    public function initiateResetForEmail(string $normalizedEmail, string $clientIp): void
    {
        $since = date('Y-m-d H:i:s', strtotime('-1 hour'));
        if ($this->requestLog->countForEmailSince($normalizedEmail, $since) >= self::MAX_REQUESTS_PER_EMAIL_PER_HOUR) {
            $this->audit->log('password_reset_throttled', 'user', null, null, null, ['scope' => 'email']);
            return;
        }
        if ($this->requestLog->countForIpSince($clientIp, $since) >= self::MAX_REQUESTS_PER_IP_PER_HOUR) {
            $this->audit->log('password_reset_throttled', 'user', null, null, null, ['scope' => 'ip']);
            return;
        }

        $this->maybePruneRequestLog();

        $user = $this->db->fetchOne(
            'SELECT id, email, name, branch_id FROM users WHERE email = ? AND deleted_at IS NULL',
            [$normalizedEmail]
        );
        if ($user === null) {
            $this->requestLog->insert($normalizedEmail, $clientIp);
            return;
        }

        $this->requestLog->insert($normalizedEmail, $clientIp);

        $userId = (int) $user['id'];
        $this->tokens->revokeUnusedForUser($userId);

        $plain = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plain);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);
        $tokenRowId = $this->tokens->insert($userId, $tokenHash, $expiresAt);

        $resetUrl = $this->absoluteResetUrl($plain);
        $loginUrl = $this->absoluteUrl('/login');
        $subject = 'Reset your staff account password';
        $body = $this->buildEmailBody((string) ($user['name'] ?? ''), $resetUrl, $loginUrl);

        $branchId = isset($user['branch_id']) && $user['branch_id'] !== null && $user['branch_id'] !== ''
            ? (int) $user['branch_id']
            : null;

        $idempotencyKey = 'email:v1:' . self::EVENT_KEY . ':token:' . $tokenRowId;
        $row = [
            'branch_id' => $branchId,
            'channel' => 'email',
            'event_key' => self::EVENT_KEY,
            'template_key' => self::EVENT_KEY,
            'idempotency_key' => $idempotencyKey,
            'recipient_type' => 'user',
            'recipient_id' => $userId,
            'recipient_address' => $normalizedEmail,
            'subject' => $subject,
            'body_text' => $body,
            'payload_json' => json_encode([
                'user_id' => $userId,
                'user_password_reset_token_id' => $tokenRowId,
            ], JSON_THROW_ON_ERROR),
            'entity_type' => 'user_password_reset_token',
            'entity_id' => $tokenRowId,
            'status' => 'pending',
            'skip_reason' => null,
            'error_summary' => null,
            'scheduled_at' => null,
        ];
        try {
            $this->outboundMessages->insert($row);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                $this->audit->log('password_reset_email_enqueue_duplicate', 'user_password_reset_token', $tokenRowId, null, $branchId, ['user_id' => $userId]);
                return;
            }
            throw $e;
        }

        $this->audit->log('password_reset_email_enqueued', 'user_password_reset_token', $tokenRowId, null, $branchId, ['user_id' => $userId]);
    }

    /**
     * Whether the plaintext token from the email link is currently valid (not consumed, not expired).
     */
    public function plainResetTokenIsCurrentlyValid(string $plainToken): bool
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '' || !preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
            return false;
        }

        return $this->tokens->findActiveByHash(hash('sha256', $plainToken)) !== null;
    }

    /**
     * @throws \InvalidArgumentException when token invalid, expired, reused, or password rules fail
     */
    public function completeResetWithToken(string $plainToken, string $newPassword, string $confirmPassword): void
    {
        $newPassword = trim($newPassword);
        $confirmPassword = trim($confirmPassword);
        if ($newPassword !== $confirmPassword) {
            throw new \InvalidArgumentException('Password and confirmation do not match.');
        }
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }

        $plainToken = trim($plainToken);
        if ($plainToken === '' || !preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
            throw new \InvalidArgumentException('This reset link is invalid or has expired.');
        }
        $tokenHash = hash('sha256', $plainToken);

        $pdo = $this->db->connection();
        $pdo->beginTransaction();
        try {
            $row = $this->tokens->findActiveByHashForUpdate($tokenHash);
            if ($row === null) {
                $pdo->rollBack();
                throw new \InvalidArgumentException('This reset link is invalid or has expired.');
            }
            $tokenId = (int) $row['id'];
            $userId = (int) $row['user_id'];

            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            try {
                $this->db->query(
                    'UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ? AND deleted_at IS NULL',
                    [$hash, $userId]
                );
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'password_changed_at')) {
                    throw $e;
                }
                $this->db->query(
                    'UPDATE users SET password_hash = ? WHERE id = ? AND deleted_at IS NULL',
                    [$hash, $userId]
                );
            }

            $this->tokens->markUsed($tokenId);
            $this->tokens->revokeUnusedForUser($userId);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log('password_reset_completed', 'user', $userId, null, null, ['user_password_reset_token_id' => $tokenId]);
    }

    private function buildEmailBody(string $name, string $resetUrl, string $loginUrl): string
    {
        $greeting = $name !== '' ? 'Hello ' . $name . ',' : 'Hello,';

        return $greeting . "\n\n"
            . "We received a request to reset the password for your staff account.\n\n"
            . "If you did not request this, you can ignore this email.\n\n"
            . "Reset link (expires in 1 hour, single use):\n"
            . $resetUrl . "\n\n"
            . "After setting a new password, sign in here:\n"
            . $loginUrl . "\n";
    }

    private function absoluteResetUrl(string $plainToken): string
    {
        $base = rtrim((string) Application::config('app.url', ''), '/');
        if ($base === '') {
            $base = '';
        }
        $path = '/password/reset/complete?token=' . rawurlencode($plainToken);

        return ($base !== '' ? $base : '') . $path;
    }

    private function absoluteUrl(string $path): string
    {
        $base = rtrim((string) Application::config('app.url', ''), '/');

        return ($base !== '' ? $base : '') . $path;
    }

    private function maybePruneRequestLog(): void
    {
        if (random_int(1, 40) !== 1) {
            return;
        }
        $this->requestLog->pruneOlderThanDays(14);
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $m = strtolower($e->getMessage());

        return str_contains($m, 'duplicate') || (string) $e->getCode() === '23000';
    }
}
