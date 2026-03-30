<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\App\Database;

/**
 * Append-only log for per-email and per-IP throttling on password reset requests.
 */
final class PasswordResetRequestLogRepository
{
    public function __construct(private Database $db)
    {
    }

    public function insert(string $normalizedEmail, string $ipAddress): void
    {
        $this->db->insert('user_password_reset_request_log', [
            'normalized_email' => $normalizedEmail,
            'ip_address' => $ipAddress,
        ]);
    }

    public function countForEmailSince(string $normalizedEmail, string $sinceMysqlDatetime): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM user_password_reset_request_log
             WHERE normalized_email = ? AND created_at >= ?',
            [$normalizedEmail, $sinceMysqlDatetime]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countForIpSince(string $ipAddress, string $sinceMysqlDatetime): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM user_password_reset_request_log
             WHERE ip_address = ? AND created_at >= ?',
            [$ipAddress, $sinceMysqlDatetime]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function pruneOlderThanDays(int $days): void
    {
        $days = max(1, min(90, $days));
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $this->db->query(
            'DELETE FROM user_password_reset_request_log WHERE created_at < ? LIMIT 50000',
            [$cutoff]
        );
    }
}
